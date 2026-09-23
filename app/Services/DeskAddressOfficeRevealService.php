<?php

namespace App\Services;

use App\Models\Crm2CityCredential;
use App\Models\DeskOrderCache;
use App\Support\DeskAddressOffice;
use App\Support\KpHttpError;
use Throwable;

/**
 * Раскрытие квартиры/офиса KP (get-address-office) с кэшем в orders_cache.
 *
 * Побочный эффект: первый live-вызов навсегда открывает кв в КП —
 * только после окна canReveal / canRevealForMaster.
 */
class DeskAddressOfficeRevealService
{
    public function __construct(
        private DeskSyncService $sync,
        private DeskFraudService $fraud,
    ) {}

    /**
     * @return array{ok: true, text: string, from_cache: bool}|array{ok: false, code: string, message: string, http: int, reveal_at?: string|null}
     */
    public function reveal(DeskOrderCache $cached, bool $allowVisitStatus = false): array
    {
        $tz = DeskAddressOffice::resolveTimezone($cached->timezone);
        $allowed = $allowVisitStatus
            ? DeskAddressOffice::canRevealForMaster($cached->call_at_local, $cached->raw_status, $tz)
            : DeskAddressOffice::canReveal($cached->call_at_local, null, $tz);

        if (! $allowed) {
            $revealAt = DeskAddressOffice::revealAt($cached->call_at_local, $tz);

            return [
                'ok' => false,
                'code' => 'reveal_not_allowed',
                'message' => $revealAt
                    ? 'Квартира будет доступна с '.$revealAt->format('d.m.Y H:i').' (за '.DeskAddressOffice::REVEAL_MINUTES_BEFORE.' мин. до заявки)'
                    : 'Нет времени заявки — квартиру показать нельзя',
                'http' => 403,
                'reveal_at' => $revealAt?->format('Y-m-d H:i:s'),
            ];
        }

        $text = trim((string) ($cached->address_office ?? ''));
        if ($text !== '') {
            return ['ok' => true, 'text' => $text, 'from_cache' => true];
        }

        if ($cached->connection?->type !== 'crm2_http') {
            return [
                'ok' => false,
                'code' => 'forbidden',
                'message' => 'Заявка не является KP-Lead.',
                'http' => 403,
            ];
        }

        $credential = null;
        if ($cached->city_id) {
            $credential = Crm2CityCredential::query()->where('city_id', $cached->city_id)->first();
        }

        try {
            $adapter = $this->sync->makeAdapter($cached->connection, 0, $credential);

            // Карточку без unlock кв в КП; office — отдельным вызовом ниже
            if (! filled($cached->customer_external_id) && method_exists($adapter, 'fetchOrder')) {
                $live = $adapter->fetchOrder((string) $cached->external_id, ['skip_office' => true]);
                if (is_array($live)) {
                    $cached = $this->sync->applyLivePayload($cached, $live);
                    $text = trim((string) ($cached->address_office ?? ''));
                }
            }

            // Единственное место, где сознательно открываем кв в КП (после окна)
            if ($text === '' && filled($cached->customer_external_id) && method_exists($adapter, 'fetchCustomerAddressOffice')) {
                $text = (string) ($adapter->fetchCustomerAddressOffice(
                    (string) $cached->customer_external_id,
                    (string) $cached->external_id
                ) ?? '');
                if ($text !== '') {
                    $cached->address_office = $text;
                    $cached->save();
                    $this->fraud->apply($cached, true);
                }
            }
        } catch (Throwable $e) {
            $message = KpHttpError::message($e);
            if (
                $credential
                && $credential->status === 'error'
                && filled($credential->last_error)
                && KpHttpError::isTransient((string) $credential->last_error)
            ) {
                $message .= ' Учётка филиала КП в статусе ошибки после сбоя связи — синхронизация восстановится, когда КП снова ответит.';
            }

            return [
                'ok' => false,
                'code' => 'source_write_failed',
                'message' => $message,
                'http' => 422,
            ];
        }

        if ($text === '') {
            return [
                'ok' => false,
                'code' => 'office_not_found',
                'message' => 'Квартира в КП не найдена',
                'http' => 404,
            ];
        }

        return ['ok' => true, 'text' => $text, 'from_cache' => false];
    }

    /**
     * Только чтение кэша без KP (для проверок / тестов).
     */
    public function cachedText(DeskOrderCache $cached): ?string
    {
        $text = trim((string) ($cached->address_office ?? ''));

        return $text !== '' ? $text : null;
    }
}
