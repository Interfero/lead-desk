<?php

namespace App\Services;

use App\Models\DeskLog;
use App\Models\DeskOrderCache;
use App\Models\DeskStat;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class DeskOrderService
{
    public function __construct(protected DeskSyncService $sync) {}

    public function baseQueryForSession(array $deskUser, bool $includeClosed = false): Builder
    {
        $query = DeskOrderCache::query()->with('connection');

        if (! $includeClosed) {
            $query->where(function ($q) {
                $q->whereNotIn('raw_status', DeskOrderCache::CLOSED_RAW)
                    ->where(function ($q2) {
                        $q2->whereNull('status')->orWhere('status', '!=', 'closed');
                    });
            });
        }

        $cityIds = $deskUser['city_ids'] ?? null;

        if (is_array($cityIds)) {
            if ($cityIds === []) {
                $query->whereRaw('0 = 1');
            } else {
                $query->whereIn('city_id', $cityIds);
            }
        }

        return $query;
    }

    public function findForSession(array $deskUser, int $id): DeskOrderCache
    {
        $order = $this->baseQueryForSession($deskUser, true)->whereKey($id)->first();
        if (! $order) {
            abort(404);
        }

        return $order;
    }

    /**
     * Найти заказ в кэше или подтянуть карточку из КП (для истории клиента).
     */
    public function findOrImportByExternal(array $deskUser, string $externalId, DeskOrderCache $fromOrder): DeskOrderCache
    {
        $externalId = preg_replace('/\D+/', '', $externalId) ?: $externalId;

        $existing = $this->baseQueryForSession($deskUser, true)
            ->where('crm_id', $fromOrder->crm_id)
            ->where('external_id', $externalId)
            ->first();
        if ($existing) {
            return $existing;
        }

        if ($fromOrder->connection?->type !== 'crm2_http') {
            throw new RuntimeException('Импорт истории доступен только для КП');
        }

        $userId = (int) ($deskUser['id'] ?? 0);
        $adapter = $this->sync->makeAdapter($fromOrder->connection, $userId, $this->credentialFor($fromOrder));
        $payload = $adapter->fetchOrder($externalId);
        if (! is_array($payload) || empty($payload['external_id'])) {
            throw new RuntimeException('Заказ #'.$externalId.' не найден в КП');
        }

        $payload['city_id'] = $payload['city_id'] ?? $fromOrder->city_id;
        if (empty($payload['city_name'])) {
            $payload['city_name'] = $fromOrder->city_name;
        }

        $cached = DeskOrderCache::query()->firstOrNew([
            'crm_id' => $fromOrder->crm_id,
            'external_id' => (string) $payload['external_id'],
        ]);
        if (! $cached->exists) {
            $cached->city_id = $fromOrder->city_id;
            $cached->city_name = $fromOrder->city_name;
            $cached->status = 'open';
            $cached->raw_status = (string) ($payload['raw_status'] ?? 'completed');
            $cached->save();
        }

        return $this->sync->applyLivePayload($cached->fresh(['connection']) ?? $cached, $payload);
    }

    /** @return array<string, int> */
    public function statusCounts(array $deskUser): array
    {
        $rows = $this->baseQueryForSession($deskUser)
            ->selectRaw('status, COUNT(*) as cnt')
            ->groupBy('status')
            ->pluck('cnt', 'status')
            ->all();

        $out = [];
        foreach (array_keys(DeskOrderCache::unifiedStatusLabels()) as $code) {
            $out[$code] = (int) ($rows[$code] ?? 0);
        }

        return $out;
    }

    public function update(DeskOrderCache $cached, array $data, array $deskUser): DeskOrderCache
    {
        $userId = (int) ($deskUser['id'] ?? 0);
        $adapter = $this->sync->makeAdapter($cached->connection, $userId, $this->credentialFor($cached));

        try {
            $adapter->updateOrder($cached->external_id, $data);

            // сразу пишем в кэш локальные поля закрытия (на случай если live-refresh не вернёт их)
            foreach (['paid_amount', 'parts_amount', 'prepayment', 'with_bso', 'with_zip', 'master_external_id'] as $key) {
                if (array_key_exists($key, $data) && $data[$key] !== null && $data[$key] !== '') {
                    $cached->{$key} = $data[$key];
                }
            }
            if (! empty($data['master_external_id'])) {
                $cached->master_external_id = (string) $data['master_external_id'];
            }
            if (! empty($data['raw_status'])) {
                $cached->raw_status = (string) $data['raw_status'];
            }
            $cached->last_synced_at = now();
            $cached->save();

            return $this->sync->refreshCachedOrder($cached->fresh(), $userId);
        } catch (Throwable $e) {
            DeskLog::write('error', $e->getMessage(), $cached->crm_id, 'update', $cached->external_id);
            throw $e;
        }
    }

    public function close(DeskOrderCache $cached, array $deskUser): array
    {
        if (empty($deskUser['can_close'])) {
            throw new RuntimeException('Нет права закрывать заказы');
        }

        // пустые комплектующие = 0 (как «нет ЗПЧ»)
        if ($cached->parts_amount === null) {
            $cached->parts_amount = 0;
            $cached->save();
        }
        if ($cached->connection?->type === 'crm2_http' && $cached->prepayment === null) {
            $cached->prepayment = 0;
            $cached->save();
        }

        $this->assertCloseable($cached);
        $userId = (int) ($deskUser['id'] ?? 0);
        $adapter = $this->sync->makeAdapter($cached->connection, $userId, $this->credentialFor($cached));
        $externalId = $cached->external_id;
        $crmId = $cached->crm_id;

        $calc = null;
        try {
            DB::transaction(function () use ($adapter, $cached, $externalId, $crmId, &$calc) {
                $closeResult = $adapter->closeOrder($externalId, [
                    'paid_amount' => $cached->paid_amount,
                    'parts_amount' => $cached->parts_amount ?? 0,
                    'prepayment' => $cached->prepayment ?? 0,
                    'with_bso' => $cached->with_bso,
                    'with_zip' => $cached->with_zip,
                    'master_external_id' => $cached->master_external_id,
                ]);
                // КП отдаёт свою расчётку; для CRM1 — локальная формула
                if (is_array($closeResult) && ($closeResult['source'] ?? null) === 'kp') {
                    $calc = $closeResult;
                } else {
                    $calc = \App\Support\DeskMasterCalc::compute(
                        (int) ($cached->paid_amount ?? 0),
                        (int) ($cached->parts_amount ?? 0),
                        $cached->order_type,
                        (bool) $cached->is_noncore,
                    );
                }
                $cached->raw_status = 'completed';
                $cached->status = 'closed';
                $cached->last_synced_at = now();
                $cached->save();
                DeskStat::incrementKey('closed_via_desk');
                DeskLog::write('info', 'closed via desk', $crmId, 'close', $externalId);
            });
        } catch (Throwable $e) {
            DeskLog::write('error', $e->getMessage(), $crmId, 'close', $externalId);
            throw $e;
        }

        return [
            'order' => $cached->fresh(['connection']),
            'calculation' => $calc,
        ];
    }

    public function assertCloseable(DeskOrderCache $cached): void
    {
        $errors = [];
        if (! filled(trim((string) $cached->master_external_id)) && ! filled(trim((string) $cached->master_name))) {
            $errors[] = 'Назначьте мастера';
        }
        if ($cached->paid_amount === null || (int) $cached->paid_amount <= 0) {
            $errors[] = 'Заполните «Оплачено клиентом»';
        }
        // null уже нормализуется в 0 перед close; здесь только явное «не задано» после нормализации не бывает
        if ($cached->parts_amount === null) {
            $errors[] = 'Заполните стоимость комплектующих (0 если нет ЗПЧ)';
        }
        $docs = is_array($cached->documents) ? $cached->documents : [];
        $contractDocs = array_filter($docs, fn ($d) => ($d['category'] ?? '') === 'contract');
        if (count($docs) < 1) {
            $errors[] = 'Загрузите документы';
        } elseif ($cached->connection?->type === 'crm2_http' && count($contractDocs) < 1 && count($docs) < 1) {
            $errors[] = 'Загрузите документы в «Договор/Чеки на услуги»';
        }
        if ($cached->connection?->type === 'crm2_http') {
            if ($cached->with_bso === null) {
                $errors[] = 'Укажите «Наличие БСО»';
            }
            if ($cached->with_zip === null) {
                $errors[] = 'Укажите «Комплектующие»';
            }
        }
        if ($errors !== []) {
            throw new RuntimeException(implode('. ', $errors));
        }
    }

    public function uploadDocument(DeskOrderCache $cached, string $category, \Illuminate\Http\UploadedFile $file, array $deskUser): DeskOrderCache
    {
        $userId = (int) ($deskUser['id'] ?? 0);
        $adapter = $this->sync->makeAdapter($cached->connection, $userId, $this->credentialFor($cached));
        $doc = $adapter->uploadDocument($cached->external_id, $category, $file);

        $docs = is_array($cached->documents) ? $cached->documents : [];
        $docs[] = $doc;
        $cached->documents = $docs;
        $cached->last_synced_at = now();
        $cached->save();

        return $cached->fresh(['connection']);
    }

    public function deleteDocument(DeskOrderCache $cached, string $documentId, array $deskUser): DeskOrderCache
    {
        $userId = (int) ($deskUser['id'] ?? 0);
        $adapter = $this->sync->makeAdapter($cached->connection, $userId, $this->credentialFor($cached));
        $adapter->deleteDocument($cached->external_id, $documentId);

        $docs = is_array($cached->documents) ? $cached->documents : [];
        $cached->documents = array_values(array_filter($docs, fn ($d) => (string) ($d['id'] ?? '') !== $documentId));
        $cached->last_synced_at = now();
        $cached->save();

        return $cached->fresh(['connection']);
    }

    protected function credentialFor(DeskOrderCache $cached): ?\App\Models\Crm2CityCredential
    {
        if ($cached->connection?->type !== 'crm2_http' || ! $cached->city_id) {
            return null;
        }

        return \App\Models\Crm2CityCredential::query()->where('city_id', $cached->city_id)->first();
    }
}
