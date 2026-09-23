<?php

namespace App\Services;

use App\Adapters\Crm1ApiAdapter;
use App\Adapters\Crm2HttpAdapter;
use App\Contracts\CrmAdapter;
use App\Models\Crm2CityCredential;
use App\Models\CrmConnection;
use App\Models\DeskLog;
use App\Models\DeskOrderCache;
use App\Models\DeskStatusMapping;
use App\Support\DeskCardMasters;
use App\Support\DeskKpHydration;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class DeskSyncService
{
    public function __construct(
        protected DeskFraudService $fraud
    ) {}

    public function makeAdapter(CrmConnection $connection, ?int $actingUserId = null, ?Crm2CityCredential $credential = null): CrmAdapter
    {
        return match ($connection->type) {
            'crm1_api' => new Crm1ApiAdapter($connection, $actingUserId),
            'crm2_http' => new Crm2HttpAdapter($connection, $credential),
            default => throw new RuntimeException("Неизвестный адаптер: {$connection->type}"),
        };
    }

    /** @return array{upserted: int, removed: int} */
    public function syncConnection(CrmConnection $connection, bool $full = false, ?int $actingUserId = null): array
    {
        if ($connection->type === 'crm2_http') {
            return $this->syncCrm2($connection, $full, $actingUserId);
        }

        return $this->syncViaAdapter($connection, $full, $actingUserId);
    }

    /**
     * @param  list<array<string, mixed>>  $orders
     */
    public function upsertHistoryOrders(CrmConnection $connection, array $orders, int $cityId): int
    {
        return $this->upsertOrders($connection, $orders, false, $cityId)['upserted'];
    }

    /**
     * Синхронизация kp по учётным данным каждого филиала.
     *
     * @return array{upserted: int, removed: int}
     */
    public function syncCrm2(CrmConnection $connection, bool $full = false, ?int $actingUserId = null, ?int $onlyCityId = null): array
    {
        $query = Crm2CityCredential::query()->orderBy('city_id');
        if ($onlyCityId) {
            $query->where('city_id', $onlyCityId);
        }
        $credentials = $query->get()->filter(fn (Crm2CityCredential $c) => $c->hasCredentials());
        if ($credentials->isEmpty()) {
            throw new RuntimeException('Нет настроенных доступов kp-lead-centre по филиалам');
        }

        $upserted = 0;
        $removed = 0;
        $errors = [];

        $first = true;
        foreach ($credentials as $cred) {
            try {
                $adapter = $this->makeAdapter($connection, $actingUserId, $cred);
                $adapter->authenticate();
                if (! $first) {
                    usleep(5_000_000); // ≥5 сек между запросами к CRM2 (ТЗ)
                }
                $first = false;
                $orders = $adapter->fetchOrders(['full' => $full]);
                $result = $this->upsertOrders($connection, $orders, $full, (int) $cred->city_id);
                // Список КП без описания/кв — сразу дожимаем карточку в наш кэш (kp_hydrate_incomplete).
                $this->hydrateIncompleteKpOrders($connection, $adapter, (int) $cred->city_id);
                // Закрытые из history/list без ЗПЧ/оплаты — дожимаем карточку (иначе amountCompRub=0 в GM).
                $this->hydrateKpFinanceGaps($connection, $adapter, (int) $cred->city_id);
                // Список КП — только активные на 1-й странице. Пропавшие из списка открытые
                // (отмены/готово) иначе навсегда остаются pending и горят красным.
                $seen = array_values(array_unique(array_map(
                    fn (array $o) => (string) ($o['external_id'] ?? ''),
                    $orders
                )));
                $result['removed'] += $this->reconcileMissingOpenKpOrders(
                    $connection,
                    $cred,
                    $seen,
                    $actingUserId
                );
                $upserted += $result['upserted'];
                $removed += $result['removed'];
                $cred->markSynced();
            } catch (Throwable $e) {
                $cred->markError($e->getMessage());
                $errors[] = ($cred->city_name ?: '#'.$cred->city_id).': '.$e->getMessage();
                DeskLog::write('error', $e->getMessage(), $connection->id, 'sync', null, [
                    'city_id' => $cred->city_id,
                ]);
            }
        }

        if ($errors !== [] && $upserted === 0) {
            $connection->markError(implode('; ', $errors));
            throw new RuntimeException(implode('; ', $errors));
        }

        if ($errors === []) {
            $connection->markSynced();
        } else {
            $connection->markError(implode('; ', $errors));
        }

        DeskLog::write('info', "crm2 sync upserted={$upserted} removed={$removed}", $connection->id, 'sync');

        return compact('upserted', 'removed');
    }

    /** @return array{upserted: int, removed: int} */
    protected function syncViaAdapter(CrmConnection $connection, bool $full = false, ?int $actingUserId = null): array
    {
        $adapter = $this->makeAdapter($connection, $actingUserId);

        try {
            $adapter->authenticate();
            $filters = ['full' => $full];
            if (! $full && $connection->last_sync_at) {
                $filters['updated_after'] = $connection->last_sync_at;
            }

            $orders = $adapter->fetchOrders($filters);
            $result = $this->upsertOrders($connection, $orders, $full);
            $connection->markSynced();
            DeskLog::write('info', "sync ok upserted={$result['upserted']} removed={$result['removed']}", $connection->id, 'sync');

            return $result;
        } catch (Throwable $e) {
            $connection->markError($e->getMessage());
            DeskLog::write('error', $e->getMessage(), $connection->id, 'sync');
            throw $e;
        }
    }

    /**
     * @param  list<array<string, mixed>>  $orders
     * @return array{upserted: int, removed: int}
     */
    protected function upsertOrders(CrmConnection $connection, array $orders, bool $full, ?int $scopeCityId = null): array
    {
        $mappings = $this->mappingsFor($connection->id);
        $seen = [];
        $upserted = 0;

        DB::transaction(function () use ($connection, $orders, $mappings, $scopeCityId, &$seen, &$upserted) {
            foreach ($orders as $payload) {
                $raw = (string) ($payload['raw_status'] ?? '');
                $map = $mappings[$raw] ?? null;
                $unified = $map['unified_status'] ?? $this->fallbackUnified($raw);
                $externalId = (string) $payload['external_id'];
                $seen[] = $externalId;

                $existing = DeskOrderCache::query()
                    ->where('crm_id', $connection->id)
                    ->where('external_id', $externalId)
                    ->first();

                // Статус и время обновляем всегда (СД, Готов и т.д.) — закрытые не выкидываем
                $row = DeskOrderCache::query()->updateOrCreate(
                    [
                        'crm_id' => $connection->id,
                        'external_id' => $externalId,
                    ],
                    [
                        'city_id' => $payload['city_id'] ?? $scopeCityId ?? $existing?->city_id,
                        'city_name' => $this->preferDisplayCityName(
                            $payload['city_name'] ?? null,
                            $existing?->city_name
                        ),
                        'rk' => $this->keepNonEmptyText(
                            $payload['rk'] ?? $payload['marketing_source'] ?? null,
                            $existing?->rk
                        ),
                        'rk_url' => array_key_exists('rk_url', $payload)
                            ? ($payload['rk_url'] ?: null)
                            : ($existing?->rk_url),
                        'status' => $unified,
                        'raw_status' => $raw !== '' ? $raw : $existing?->raw_status,
                        'client_name' => $payload['client_name'] ?? $existing?->client_name,
                        'client_age' => $payload['client_age'] ?? $existing?->client_age,
                        'customer_external_id' => $payload['customer_external_id'] ?? $existing?->customer_external_id,
                        'phone' => $payload['phone'] ?? $existing?->phone,
                        'phone_norm' => $this->fraud->normalizePhone((string) ($payload['phone'] ?? $existing?->phone ?? '')) ?: null,
                        'address' => $payload['address'] ?? $existing?->address,
                        'address_norm' => (($addrNorm = $this->fraud->normalizeAddress((string) ($payload['address'] ?? $existing?->address ?? ''))) !== '' ? $addrNorm : null),
                        'address_office' => array_key_exists('address_office', $payload) && $payload['address_office']
                            ? $payload['address_office']
                            : ($existing?->address_office),
                        'description' => $payload['description'] ?? $existing?->description,
                        'master_name' => $this->masterNameFromPayload($payload, $existing?->master_name),
                        'master_external_id' => $this->masterExternalIdFromPayload(
                            $payload,
                            $existing?->master_external_id,
                            $existing?->master_name
                        ),
                        'total_amount' => $payload['total_amount'] ?? $existing?->total_amount,
                        'paid_amount' => $payload['paid_amount'] ?? $existing?->paid_amount,
                        'parts_amount' => $payload['parts_amount'] ?? $existing?->parts_amount,
                        'prepayment' => array_key_exists('prepayment', $payload) ? $payload['prepayment'] : ($existing?->prepayment),
                        'with_bso' => array_key_exists('with_bso', $payload) ? $payload['with_bso'] : ($existing?->with_bso),
                        'with_zip' => array_key_exists('with_zip', $payload) ? $payload['with_zip'] : ($existing?->with_zip),
                        'created_at_local' => $payload['created_at_local'] ?? $existing?->created_at_local,
                        'call_at_local' => $payload['call_at_local'] ?? $existing?->call_at_local,
                        'timezone' => $payload['timezone'] ?? $existing?->timezone,
                        'updated_at_local' => $payload['updated_at_local'] ?? $existing?->updated_at_local,
                        'order_type' => $payload['order_type'] ?? $existing?->order_type,
                        ...$this->calcMetaFromPayload($payload, $existing),
                        'needs_feedback' => array_key_exists('needs_feedback', $payload) && $payload['needs_feedback'] !== null
                            ? (bool) $payload['needs_feedback']
                            : (bool) ($existing?->needs_feedback ?? false),
                        'comments' => $payload['comments'] ?? $existing?->comments,
                        'documents' => ! empty($payload['documents'])
                            ? $payload['documents']
                            : ($existing?->documents ?? []),
                        'client_history' => array_key_exists('client_history', $payload) && is_array($payload['client_history'])
                            ? $payload['client_history']
                            : ($existing?->client_history ?? null),
                        'priority' => $payload['priority'] ?? $existing?->priority ?? 0,
                        'row_highlight' => $payload['row_highlight'] ?? $existing?->row_highlight,
                        'hash' => $payload['hash'] ?? $existing?->hash,
                        'last_synced_at' => now(),
                    ]
                );
                $this->fraud->apply($row, true);
                $upserted++;
            }
        });

        $removed = 0;
        if ($full) {
            // Не чистим закрытые: список КП часто их не отдаёт, но статус/время уже в кэше
            $removed = DeskOrderCache::query()
                ->where('crm_id', $connection->id)
                ->when($scopeCityId, fn ($q) => $q->where('city_id', $scopeCityId))
                ->when($seen !== [], fn ($q) => $q->whereNotIn('external_id', $seen))
                ->where(function ($q) {
                    $q->whereNotIn('raw_status', DeskOrderCache::CLOSED_RAW)
                        ->orWhereNull('raw_status');
                })
                ->where(function ($q) {
                    $q->whereNull('status')->orWhere('status', '!=', 'closed');
                })
                ->delete();
        }

        return compact('upserted', 'removed');
    }

    public function refreshCachedOrder(DeskOrderCache $cached, ?int $actingUserId = null): DeskOrderCache
    {
        $connection = $cached->connection;
        $credential = null;
        if ($connection->type === 'crm2_http' && $cached->city_id) {
            $credential = Crm2CityCredential::query()->where('city_id', $cached->city_id)->first();
        }
        $adapter = $this->makeAdapter($connection, $actingUserId, $credential);
        $payload = $adapter->fetchOrder($cached->external_id);
        if (! $payload) {
            // CRM2: не удаляем кэш при ошибке карточки — список уже валиден
            if ($connection->type === 'crm2_http') {
                return $cached->fresh(['connection']) ?? $cached;
            }
            $cached->delete();
            throw new RuntimeException('Заказ не найден в CRM');
        }

        $mappings = $this->mappingsFor($connection->id);
        $raw = (string) ($payload['raw_status'] ?? $cached->raw_status ?? '');
        $map = $mappings[$raw] ?? null;
        $unified = $map['unified_status'] ?? $this->fallbackUnified($raw);

        $cached->fill([
            'city_id' => $payload['city_id'] ?? $cached->city_id,
            'city_name' => $this->preferDisplayCityName(
                $payload['city_name'] ?? null,
                $cached->city_name
            ),
            'rk' => $this->keepNonEmptyText(
                $payload['rk'] ?? $payload['marketing_source'] ?? null,
                $cached->rk
            ),
            'rk_url' => array_key_exists('rk_url', $payload)
                ? ($payload['rk_url'] ?: null)
                : $cached->rk_url,
            'status' => $unified,
            'raw_status' => $raw !== '' ? $raw : $cached->raw_status,
            'client_name' => $payload['client_name'] ?? $cached->client_name,
            'client_age' => $payload['client_age'] ?? $cached->client_age,
            'customer_external_id' => $payload['customer_external_id'] ?? $cached->customer_external_id,
            'phone' => $payload['phone'] ?? $cached->phone,
            'phone_norm' => $this->fraud->normalizePhone((string) ($payload['phone'] ?? $cached->phone ?? '')) ?: null,
            'address' => $payload['address'] ?? $cached->address,
            'address_norm' => (($addrNorm = $this->fraud->normalizeAddress((string) ($payload['address'] ?? $cached->address ?? ''))) !== '' ? $addrNorm : null),
            'address_office' => array_key_exists('address_office', $payload) && $payload['address_office']
                ? $payload['address_office']
                : $cached->address_office,
            'description' => $payload['description'] ?? $cached->description,
            'master_name' => $this->masterNameFromPayload($payload, $cached->master_name),
            'master_external_id' => $this->masterExternalIdFromPayload(
                $payload,
                $cached->master_external_id,
                $cached->master_name
            ),
            'total_amount' => $payload['total_amount'] ?? $cached->total_amount,
            'paid_amount' => $payload['paid_amount'] ?? $cached->paid_amount,
            'parts_amount' => $payload['parts_amount'] ?? $cached->parts_amount,
            'prepayment' => array_key_exists('prepayment', $payload) ? $payload['prepayment'] : $cached->prepayment,
            'with_bso' => array_key_exists('with_bso', $payload) ? $payload['with_bso'] : $cached->with_bso,
            'with_zip' => array_key_exists('with_zip', $payload) ? $payload['with_zip'] : $cached->with_zip,
            'created_at_local' => $payload['created_at_local'] ?? $cached->created_at_local,
            'call_at_local' => $payload['call_at_local'] ?? $cached->call_at_local,
            'timezone' => $payload['timezone'] ?? $cached->timezone,
            'updated_at_local' => $payload['updated_at_local'] ?? $cached->updated_at_local,
            'order_type' => $payload['order_type'] ?? $cached->order_type,
            ...$this->calcMetaFromPayload($payload, $cached),
            'needs_feedback' => array_key_exists('needs_feedback', $payload) && $payload['needs_feedback'] !== null
                ? (bool) $payload['needs_feedback']
                : $cached->needs_feedback,
            'comments' => $payload['comments'] ?? $cached->comments,
            // Пустой documents из КП-парсера не должен затирать уже загруженные через desk
            'documents' => ! empty($payload['documents']) ? $payload['documents'] : ($cached->documents ?? []),
            'client_history' => array_key_exists('client_history', $payload) && is_array($payload['client_history'])
                ? $payload['client_history']
                : $cached->client_history,
            'priority' => $payload['priority'] ?? $cached->priority ?? 0,
            'hash' => $payload['hash'] ?? $cached->hash,
            'last_synced_at' => now(),
        ])->save();

        $this->fraud->apply($cached, true);
        $this->rememberMastersFromPayload($cached, $payload);

        return $cached->fresh(['connection']);
    }

    /**
     * Применить уже загруженный payload без второго HTTP-запроса.
     *
     * @param  array<string, mixed>  $payload
     */
    public function applyLivePayload(DeskOrderCache $cached, array $payload): DeskOrderCache
    {
        $mappings = $this->mappingsFor((int) $cached->crm_id);
        $raw = (string) ($payload['raw_status'] ?? $cached->raw_status ?? '');
        $map = $mappings[$raw] ?? null;
        $unified = $map['unified_status'] ?? $this->fallbackUnified($raw);

        $cached->fill([
            'city_id' => $payload['city_id'] ?? $cached->city_id,
            'city_name' => $this->preferDisplayCityName(
                $payload['city_name'] ?? null,
                $cached->city_name
            ),
            'rk' => $this->keepNonEmptyText(
                $payload['rk'] ?? $payload['marketing_source'] ?? null,
                $cached->rk
            ),
            'rk_url' => array_key_exists('rk_url', $payload)
                ? ($payload['rk_url'] ?: null)
                : $cached->rk_url,
            'status' => $unified,
            'raw_status' => $raw !== '' ? $raw : $cached->raw_status,
            'client_name' => $payload['client_name'] ?? $cached->client_name,
            'client_age' => $payload['client_age'] ?? $cached->client_age,
            'customer_external_id' => $payload['customer_external_id'] ?? $cached->customer_external_id,
            'phone' => $payload['phone'] ?? $cached->phone,
            'phone_norm' => $this->fraud->normalizePhone((string) ($payload['phone'] ?? $cached->phone ?? '')) ?: null,
            'address' => $payload['address'] ?? $cached->address,
            'address_norm' => (($addrNorm = $this->fraud->normalizeAddress((string) ($payload['address'] ?? $cached->address ?? ''))) !== '' ? $addrNorm : null),
            'address_office' => array_key_exists('address_office', $payload) && $payload['address_office']
                ? $payload['address_office']
                : $cached->address_office,
            'description' => $payload['description'] ?? $cached->description,
            'master_name' => $this->masterNameFromPayload($payload, $cached->master_name),
            'master_external_id' => $this->masterExternalIdFromPayload(
                $payload,
                $cached->master_external_id,
                $cached->master_name
            ),
            'total_amount' => $payload['total_amount'] ?? $cached->total_amount,
            'paid_amount' => $payload['paid_amount'] ?? $cached->paid_amount,
            'parts_amount' => $payload['parts_amount'] ?? $cached->parts_amount,
            'prepayment' => array_key_exists('prepayment', $payload) ? $payload['prepayment'] : $cached->prepayment,
            'with_bso' => array_key_exists('with_bso', $payload) ? $payload['with_bso'] : $cached->with_bso,
            'with_zip' => array_key_exists('with_zip', $payload) ? $payload['with_zip'] : $cached->with_zip,
            'created_at_local' => $payload['created_at_local'] ?? $cached->created_at_local,
            'call_at_local' => $payload['call_at_local'] ?? $cached->call_at_local,
            'timezone' => $payload['timezone'] ?? $cached->timezone,
            'updated_at_local' => $payload['updated_at_local'] ?? $cached->updated_at_local,
            'order_type' => $payload['order_type'] ?? $cached->order_type,
            ...$this->calcMetaFromPayload($payload, $cached),
            'needs_feedback' => array_key_exists('needs_feedback', $payload) && $payload['needs_feedback'] !== null
                ? (bool) $payload['needs_feedback']
                : $cached->needs_feedback,
            'comments' => $payload['comments'] ?? $cached->comments,
            'documents' => ! empty($payload['documents']) ? $payload['documents'] : ($cached->documents ?? []),
            'client_history' => array_key_exists('client_history', $payload) && is_array($payload['client_history'])
                ? $payload['client_history']
                : $cached->client_history,
            'priority' => $payload['priority'] ?? $cached->priority ?? 0,
            'hash' => $payload['hash'] ?? $cached->hash,
            'last_synced_at' => now(),
        ])->save();

        $this->fraud->apply($cached, true);
        $this->rememberMastersFromPayload($cached, $payload);

        return $cached->fresh(['connection']) ?? $cached;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function rememberMastersFromPayload(DeskOrderCache $cached, array $payload): void
    {
        $masters = $payload['masters'] ?? null;
        if (! is_array($masters) || $masters === []) {
            return;
        }

        DeskCardMasters::remember(
            (int) $cached->crm_id,
            $cached->city_id ? (int) $cached->city_id : null,
            $masters
        );
    }

    /**
     * Имя мастера из КП.
     *
     * Пустая ячейка списка/истории НЕ должна отвязывать заявку от мастера
     * (иначе GM metrics «теряют» completed: Order not found при живой строке в кэше).
     * Новое непустое имя — принимаем (переназначение).
     *
     * @param  array<string, mixed>  $payload
     */
    protected function masterNameFromPayload(array $payload, ?string $existing): ?string
    {
        $touched = array_key_exists('master_name', $payload)
            || array_key_exists('master_id', $payload)
            || array_key_exists('master_external_id', $payload);
        if (! $touched) {
            return $existing;
        }
        $name = $payload['master_name'] ?? null;
        if ($name === null || $name === '') {
            return $existing;
        }

        return (string) $name;
    }

    /**
     * Employee id мастера из КП.
     *
     * Список/история почти никогда не отдают id — нельзя затирать id с карточки.
     * Если в payload пришло другое непустое имя без id — сбрасываем устаревший id.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function masterExternalIdFromPayload(
        array $payload,
        mixed $existing,
        ?string $existingName = null
    ): mixed {
        $touched = array_key_exists('master_name', $payload)
            || array_key_exists('master_id', $payload)
            || array_key_exists('master_external_id', $payload);
        if (! $touched) {
            return $existing;
        }

        $id = $payload['master_external_id'] ?? $payload['master_id'] ?? null;
        if ($id !== null && $id !== '') {
            return $id;
        }

        $name = $payload['master_name'] ?? null;
        if (
            $name !== null
            && $name !== ''
            && $existingName !== null
            && $existingName !== ''
            && trim((string) $name) !== trim((string) $existingName)
        ) {
            // Переназначение по имени из списка без employee id — старый id чужой.
            return null;
        }

        return $existing;
    }

    /** @return array<string, array{unified_status: string, is_closed: bool}> */

    /**
     * Список КП без карточки: описание, id клиента и кв дожимаем сразу в кэш.
     */
    public function hydrateIncompleteKpOrders(
        CrmConnection $connection,
        CrmAdapter $adapter,
        int $cityId,
        int $limit = 8
    ): int {
        if (! method_exists($adapter, 'fetchOrder')) {
            return 0;
        }

        $incomplete = DeskOrderCache::query()
            ->where('crm_id', $connection->id)
            ->where('city_id', $cityId)
            ->where(function ($q) {
                $q->whereNotIn('raw_status', DeskOrderCache::CLOSED_RAW)
                    ->orWhereNull('raw_status');
            })
            ->where(function ($q) {
                $q->whereNull('status')->orWhere('status', '!=', 'closed');
            })
            ->where(function ($q) {
                $q->whereNull('customer_external_id')
                    ->orWhere('customer_external_id', '');
            })
            ->orderByRaw('CASE WHEN customer_external_id IS NULL OR customer_external_id = \'\' THEN 0 ELSE 1 END')
            ->orderByDesc('call_at_local')
            ->limit($limit)
            ->get();

        $hydrated = 0;
        foreach ($incomplete as $cached) {
            if (! DeskKpHydration::isIncomplete(
                $cached->customer_external_id !== null ? (string) $cached->customer_external_id : null,
                $cached->description !== null ? (string) $cached->description : null,
                $cached->comments !== null ? (string) $cached->comments : null,
            )) {
                continue;
            }
            try {
                $payload = $adapter->fetchOrder((string) $cached->external_id);
                if (! is_array($payload) || $payload === []) {
                    continue;
                }
                $this->applyLivePayload($cached, $payload);
                $hydrated++;
            } catch (Throwable $e) {
                DeskLog::write('error', 'hydrate kp #'.$cached->external_id.': '.$e->getMessage(), $connection->id, 'sync', $cached->external_id, [
                    'city_id' => $cityId,
                ]);
            }
            usleep(150_000);
        }

        if ($hydrated > 0) {
            DeskLog::write('info', 'kp_hydrate_incomplete: '.$hydrated, $connection->id, 'sync', null, [
                'city_id' => $cityId,
            ]);
        }

        return $hydrated;
    }

    /**
     * Закрытые KP без paid/parts: list/history не скрейпят spares_cost.
     * Без detail GM всегда видит amountCompRub=0 и net = «Сумма» списка.
     */
    public function hydrateKpFinanceGaps(
        CrmConnection $connection,
        CrmAdapter $adapter,
        int $cityId,
        int $limit = 6
    ): int {
        if (! method_exists($adapter, 'fetchOrder')) {
            return 0;
        }

        $gaps = DeskOrderCache::query()
            ->where('crm_id', $connection->id)
            ->where('city_id', $cityId)
            ->where('raw_status', 'completed')
            ->where(function ($q) {
                $q->whereNull('parts_amount')
                    ->orWhereNull('paid_amount')
                    ->orWhereNull('master_external_id');
            })
            ->orderByDesc('created_at_local')
            ->limit($limit)
            ->get();

        $hydrated = 0;
        foreach ($gaps as $cached) {
            try {
                $payload = $adapter->fetchOrder((string) $cached->external_id);
                if (! is_array($payload) || $payload === []) {
                    continue;
                }
                $this->applyLivePayload($cached, $payload);
                $hydrated++;
            } catch (Throwable $e) {
                DeskLog::write('error', 'hydrate finance kp #'.$cached->external_id.': '.$e->getMessage(), $connection->id, 'sync', $cached->external_id, [
                    'city_id' => $cityId,
                ]);
            }
            usleep(150_000);
        }

        if ($hydrated > 0) {
            DeskLog::write('info', 'kp_hydrate_finance: '.$hydrated, $connection->id, 'sync', null, [
                'city_id' => $cityId,
            ]);
        }

        return $hydrated;
    }

    protected function keepNonEmptyText(mixed $incoming, mixed $existing): mixed
    {
        if (is_string($incoming) && trim($incoming) !== '') {
            return $incoming;
        }

        return $existing;
    }

    /**
     * Список КП часто отдаёт только филиал («Псков»), а карточка — НП
     * «Псков (рабочий посёлок Палкино)». Не затираем более точное имя.
     */
    protected function preferDisplayCityName(mixed $incoming, mixed $existing): ?string
    {
        $incoming = trim((string) $incoming);
        $existing = trim((string) $existing);

        if ($incoming === '') {
            return $existing !== '' ? $existing : null;
        }
        if ($existing === '') {
            return $incoming;
        }

        $incomingHasLocality = str_contains($incoming, '(');
        $existingHasLocality = str_contains($existing, '(');
        if ($existingHasLocality && ! $incomingHasLocality) {
            return $existing;
        }
        if (! $existingHasLocality && $incomingHasLocality) {
            return $incoming;
        }
        if (str_starts_with($existing, $incoming) && mb_strlen($existing) > mb_strlen($incoming)) {
            return $existing;
        }

        return $incoming;
    }

    /**
     * Открытые в кэше, которых нет в текущем списке КП → перечитать карточку
     * (или удалить, если в КП уже нет). Иначе pending/красные висят сутками.
     *
     * @param  list<string>  $seenExternalIds
     */
    protected function reconcileMissingOpenKpOrders(
        CrmConnection $connection,
        Crm2CityCredential $cred,
        array $seenExternalIds,
        ?int $actingUserId = null,
        int $limit = 30
    ): int {
        $seenExternalIds = array_values(array_filter($seenExternalIds, fn ($id) => $id !== ''));

        $stale = DeskOrderCache::query()
            ->where('crm_id', $connection->id)
            ->where('city_id', (int) $cred->city_id)
            ->where(function ($q) {
                $q->whereNotIn('raw_status', DeskOrderCache::CLOSED_RAW)
                    ->orWhereNull('raw_status');
            })
            ->where(function ($q) {
                $q->whereNull('status')->orWhere('status', '!=', 'closed');
            })
            ->when($seenExternalIds !== [], fn ($q) => $q->whereNotIn('external_id', $seenExternalIds))
            ->orderByRaw("CASE WHEN raw_status = 'pending' THEN 0 ELSE 1 END")
            ->orderBy('call_at_local')
            ->limit($limit)
            ->get();

        if ($stale->isEmpty()) {
            return 0;
        }

        $adapter = $this->makeAdapter($connection, $actingUserId, $cred);
        $fixed = 0;

        foreach ($stale as $cached) {
            try {
                $payload = $adapter->fetchOrder((string) $cached->external_id);
                if (! is_array($payload) || $payload === []) {
                    $cached->delete();
                    $fixed++;
                    continue;
                }
                $this->applyLivePayload($cached, $payload);
                $fixed++;
            } catch (Throwable $e) {
                DeskLog::write('error', 'reconcile kp #'.$cached->external_id.': '.$e->getMessage(), $connection->id, 'sync', $cached->external_id, [
                    'city_id' => $cred->city_id,
                ]);
            }
            usleep(150_000);
        }

        if ($fixed > 0) {
            DeskLog::write('info', "kp reconcile missing open: {$fixed}", $connection->id, 'sync', null, [
                'city_id' => $cred->city_id,
            ]);
        }

        return $fixed;
    }

    protected function mappingsFor(int $crmId): array
    {
        return DeskStatusMapping::query()->where('crm_id', $crmId)->get()
            ->mapWithKeys(fn ($m) => [
                $m->crm_status_code => [
                    'unified_status' => $m->unified_status,
                    'is_closed' => $m->is_closed,
                ],
            ])->all();
    }

    protected function fallbackUnified(string $raw): string
    {
        return match ($raw) {
            'callback', 'not_processed', 'pending' => 'new',
            'on_way' => 'on_way',
            'in_progress', 'in_progress_sd', 'review' => 'in_progress',
            'completed' => 'ready',
            'cancelled_cc', 'cancelled_city', 'rejected' => 'closed',
            default => 'in_progress',
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{order_core: ?string, is_noncore: bool, is_long_trip: bool, is_satellite: bool, is_partner_order: bool}
     */
    protected function calcMetaFromPayload(array $payload, mixed $existing): array
    {
        $orderCore = array_key_exists('order_core', $payload)
            ? ($payload['order_core'] !== null && $payload['order_core'] !== '' ? (string) $payload['order_core'] : null)
            : ($existing?->order_core ?? null);

        if (array_key_exists('is_noncore', $payload)) {
            $isNoncore = (bool) $payload['is_noncore'];
        } elseif ($orderCore !== null) {
            $isNoncore = $orderCore === 'non_core';
        } else {
            $isNoncore = (bool) ($existing?->is_noncore ?? false);
        }

        if ($orderCore === null && $isNoncore) {
            $orderCore = 'non_core';
        }

        return [
            'order_core' => $orderCore,
            'is_noncore' => $isNoncore,
            'is_long_trip' => array_key_exists('is_long_trip', $payload)
                ? (bool) $payload['is_long_trip']
                : (bool) ($existing?->is_long_trip ?? false),
            'is_satellite' => array_key_exists('is_satellite', $payload)
                ? (bool) $payload['is_satellite']
                : (bool) ($existing?->is_satellite ?? false),
            'is_partner_order' => array_key_exists('is_partner_order', $payload)
                ? (bool) $payload['is_partner_order']
                : (bool) ($existing?->is_partner_order ?? false),
        ];
    }
}
