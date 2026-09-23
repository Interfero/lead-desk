<?php

namespace App\Http\Controllers;

use App\Models\DeskOrderCache;
use App\Services\DeskAddressOfficeRevealService;
use App\Services\DeskOrderService;
use App\Support\KpHttpError;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Server-to-server: Lead Control / Guild Master пишут KP через адаптер Desk.
 *
 * Auth: Bearer DESK_INTERNAL_TOKEN (тот же, что для status).
 * ID: принимаем `2651619` и `kp-2651619` (канон во внешнем API гильдии — `kp-{n}`).
 */
class InternalOrderWriteController extends Controller
{
    private const CLOSE_FROM_RAW = ['on_way', 'in_progress', 'in_progress_sd'];

    private const SD_FROM_RAW = ['on_way', 'in_progress'];

    private const DOC_CATEGORIES = ['contract', 'receipts', 'parts_photos', 'storage_receipt'];

    /** max KB — как в Desk UI upload */
    private const DOC_MAX_KB = 20480;

    public function __construct(
        private DeskOrderService $orders,
        private DeskAddressOfficeRevealService $officeReveal,
    ) {}

    /**
     * Раскрыть квартиру/офис KP для GM (тот же get-address-office, что кнопка Desk).
     * Окно: 30 мин до call_at ИЛИ статус on_way|in_progress|in_progress_sd.
     * Кэш-first: повторный вызов не долбит КП.
     */
    public function revealOffice(string $externalId): JsonResponse
    {
        $externalId = $this->normalizeExternalId($externalId);
        $cached = $this->findKpOrder($externalId);
        if ($cached instanceof JsonResponse) {
            return $cached;
        }

        $result = $this->officeReveal->reveal($cached, allowVisitStatus: true);
        if (! ($result['ok'] ?? false)) {
            return $this->error(
                (string) ($result['code'] ?? 'source_write_failed'),
                (string) ($result['message'] ?? 'Не удалось показать квартиру'),
                (int) ($result['http'] ?? 422),
                array_filter([
                    'reveal_at' => $result['reveal_at'] ?? null,
                ], static fn ($v) => $v !== null)
            );
        }

        return response()->json([
            'ok' => true,
            'external_id' => $cached->external_id,
            'text' => $result['text'],
            'from_cache' => (bool) ($result['from_cache'] ?? false),
        ]);
    }

    public function updateStatus(Request $request, string $externalId): JsonResponse
    {
        $externalId = $this->normalizeExternalId($externalId);
        $validated = $request->validate([
            'raw_status' => ['required', 'string', 'max:64'],
            'comment' => ['nullable', 'string', 'max:2000'],
            'master_external_id' => ['nullable', 'string', 'max:64'],
        ]);

        $cached = $this->findKpOrder($externalId);
        if ($cached instanceof JsonResponse) {
            return $cached;
        }

        $target = (string) $validated['raw_status'];

        if ($this->isFinal($cached)) {
            return $this->error('order_already_final', 'Заявка уже закрыта или в финальном статусе.', 409, [
                'raw_status' => $cached->raw_status,
                'status' => $cached->status,
            ]);
        }

        // Идемпотентность: уже в целевом статусе — успех без повторной записи в КП.
        if ((string) $cached->raw_status === $target) {
            return response()->json([
                'ok' => true,
                'idempotent' => true,
                'external_id' => $cached->external_id,
                'raw_status' => $cached->raw_status,
                'status' => $cached->status,
            ]);
        }

        $payload = [
            'raw_status' => $target,
        ];
        // comment на status игнорируем: в «Комментарий филиала» пишем только отписки
        // мастера (masterComment на close/sd), не служебные «Принято через GM» и т.п.
        if (! empty($validated['master_external_id'])) {
            $payload['master_external_id'] = $validated['master_external_id'];
        }

        try {
            $updated = $this->orders->update($cached, $payload, $this->internalDeskUser(canClose: false));

            return response()->json([
                'ok' => true,
                'idempotent' => false,
                'external_id' => $updated->external_id,
                'raw_status' => $updated->raw_status,
                'status' => $updated->status,
                'gm_status' => $updated->gm_status,
            ]);
        } catch (Throwable $e) {
            return $this->writeFailed($e);
        }
    }

    public function uploadDocument(Request $request, string $externalId): JsonResponse
    {
        $externalId = $this->normalizeExternalId($externalId);

        $file = $request->file('file');
        if ($file instanceof UploadedFile && $file->getSize() !== false && $file->getSize() > self::DOC_MAX_KB * 1024) {
            return $this->error('payload_too_large', 'Файл слишком большой (макс. '.self::DOC_MAX_KB.' КБ).', 413);
        }

        try {
            $validated = $request->validate([
                'category' => 'required|in:'.implode(',', self::DOC_CATEGORIES),
                'file' => 'required|file|max:'.self::DOC_MAX_KB.'|mimes:jpg,jpeg,png,gif,webp,bmp',
            ]);
        } catch (ValidationException $e) {
            $msg = collect($e->errors())->flatten()->first() ?? 'Ошибка валидации';
            if (is_string($msg) && (str_contains(mb_strtolower($msg), 'may not be greater') || str_contains(mb_strtolower($msg), 'больше'))) {
                return $this->error('payload_too_large', 'Файл слишком большой (макс. '.self::DOC_MAX_KB.' КБ).', 413);
            }
            throw $e;
        }

        $cached = $this->findKpOrder($externalId);
        if ($cached instanceof JsonResponse) {
            return $cached;
        }

        try {
            $updated = $this->orders->uploadDocument(
                $cached,
                (string) $validated['category'],
                $request->file('file'),
                $this->internalDeskUser(canClose: false)
            );

            $docs = is_array($updated->documents) ? $updated->documents : [];
            $last = $docs !== [] ? $docs[array_key_last($docs)] : null;

            return response()->json([
                'ok' => true,
                'external_id' => $updated->external_id,
                'document' => $last,
                'documents' => $docs,
            ]);
        } catch (Throwable $e) {
            return $this->writeFailed($e);
        }
    }

    /**
     * Закрытие KP («Готов» / completed) — канон path: …/close.
     * Тело в контракте гильдии: amountPaidRub, amountCompRub, masterComment.
     */
    public function close(Request $request, string $externalId): JsonResponse
    {
        $externalId = $this->normalizeExternalId($externalId);
        $validated = $request->validate([
            'amountPaidRub' => ['required', 'integer', 'min:0'],
            'amountCompRub' => ['required', 'integer', 'min:0'],
            'masterComment' => ['required', 'string', 'min:1', 'max:4000'],
        ]);

        $cached = $this->findKpOrder($externalId);
        if ($cached instanceof JsonResponse) {
            return $cached;
        }

        if ($this->isFinal($cached)) {
            return $this->error('order_already_final', 'Заявка уже закрыта или в финальном статусе.', 409, [
                'raw_status' => $cached->raw_status,
                'status' => $cached->status,
            ]);
        }

        $raw = mb_strtolower((string) $cached->raw_status);
        if (! in_array($raw, self::CLOSE_FROM_RAW, true)) {
            return $this->error(
                'invalid_status_transition',
                'Нельзя закрыть заявку из статуса «'.($cached->raw_status ?: '—').'».',
                409,
                ['raw_status' => $cached->raw_status, 'status' => $cached->status]
            );
        }

        $paid = (int) $validated['amountPaidRub'];
        $parts = (int) $validated['amountCompRub'];
        if ($paid <= 0) {
            return $this->error('validation_error', 'amountPaidRub должен быть больше 0.', 422);
        }

        $comment = trim((string) $validated['masterComment']);
        $commentBlock = "Отписка мастера при закрытии заявки\n".$comment;

        // Дефолты для КП, если гильдия не шлёт флаги формы Desk
        $withBso = $cached->with_bso;
        if ($withBso === null) {
            $docs = is_array($cached->documents) ? $cached->documents : [];
            $hasContract = count(array_filter($docs, fn ($d) => ($d['category'] ?? '') === 'contract')) > 0;
            $withBso = $hasContract || $paid >= 3000 ? 1 : 0;
        }
        $withZip = $cached->with_zip;
        if ($withZip === null) {
            $withZip = $parts > 0 ? 1 : 0;
        }

        $cached->paid_amount = $paid;
        $cached->parts_amount = $parts;
        $cached->prepayment = $cached->prepayment ?? 0;
        $cached->with_bso = (int) $withBso;
        $cached->with_zip = (int) $withZip;
        $cached->save();

        $deskUser = $this->internalDeskUser(canClose: true);

        try {
            // Суммы + отписка в КП (без смены статуса — closeOrder ставит «Готов»)
            $this->orders->update($cached, [
                'paid_amount' => $paid,
                'parts_amount' => $parts,
                'prepayment' => (int) ($cached->prepayment ?? 0),
                'with_bso' => (int) $cached->with_bso,
                'with_zip' => (int) $cached->with_zip,
                'comment' => $commentBlock,
            ], $deskUser);

            $result = $this->orders->close($cached->fresh(['connection']), $deskUser);
            $closed = $result['order'];

            return response()->json([
                'ok' => true,
                'external_id' => $closed->external_id,
                'raw_status' => $closed->raw_status,
                'status' => $closed->status,
                'paid_amount' => $closed->paid_amount,
                'parts_amount' => $closed->parts_amount,
                'calculation' => $result['calculation'],
            ]);
        } catch (Throwable $e) {
            $msg = KpHttpError::message($e);
            // assertCloseable и бизнес-проверки КП — не маскируем под already_final
            if (str_contains($msg, 'Назначьте мастера')
                || str_contains($msg, 'Оплачено')
                || str_contains($msg, 'документ')
                || str_contains($msg, 'БСО')
                || str_contains($msg, 'Комплектующие')
                || str_contains($msg, 'Нет права')) {
                return $this->error('validation_error', $msg, 422);
            }

            return $this->writeFailed($e);
        }
    }

    /**
     * Перевод в СД (in_progress_sd) + отписка.
     */
    public function moveToSd(Request $request, string $externalId): JsonResponse
    {
        $externalId = $this->normalizeExternalId($externalId);
        $validated = $request->validate([
            'masterComment' => ['required', 'string', 'min:1', 'max:4000'],
        ]);

        $cached = $this->findKpOrder($externalId);
        if ($cached instanceof JsonResponse) {
            return $cached;
        }

        if ($this->isFinal($cached)) {
            return $this->error('order_already_final', 'Заявка уже закрыта или в финальном статусе.', 409, [
                'raw_status' => $cached->raw_status,
                'status' => $cached->status,
            ]);
        }

        if ((string) $cached->raw_status === 'in_progress_sd') {
            return response()->json([
                'ok' => true,
                'idempotent' => true,
                'external_id' => $cached->external_id,
                'raw_status' => $cached->raw_status,
                'status' => $cached->status,
            ]);
        }

        $raw = mb_strtolower((string) $cached->raw_status);
        if (! in_array($raw, self::SD_FROM_RAW, true)) {
            return $this->error(
                'invalid_status_transition',
                'Нельзя перевести в СД из статуса «'.($cached->raw_status ?: '—').'».',
                409,
                ['raw_status' => $cached->raw_status, 'status' => $cached->status]
            );
        }

        $commentBlock = "Отписка мастера при переводе в СД\n".trim((string) $validated['masterComment']);

        try {
            $updated = $this->orders->update($cached, [
                'raw_status' => 'in_progress_sd',
                'comment' => $commentBlock,
            ], $this->internalDeskUser(canClose: false));

            return response()->json([
                'ok' => true,
                'idempotent' => false,
                'external_id' => $updated->external_id,
                'raw_status' => $updated->raw_status,
                'status' => $updated->status,
                'gm_status' => $updated->gm_status,
            ]);
        } catch (Throwable $e) {
            return $this->writeFailed($e);
        }
    }

    /** Канон: цифровой external_id КП; `kp-` префикс снимаем. */
    private function normalizeExternalId(string $externalId): string
    {
        $externalId = trim($externalId);
        if (preg_match('/^kp-(\d+)$/i', $externalId, $m) === 1) {
            return $m[1];
        }

        $digits = preg_replace('/\D+/', '', $externalId);

        return $digits !== null && $digits !== '' ? $digits : $externalId;
    }

    /**
     * @return DeskOrderCache|JsonResponse
     */
    private function findKpOrder(string $externalId): DeskOrderCache|JsonResponse
    {
        $crmId = (int) config('desk.kp_crm_id', 2);
        $cached = DeskOrderCache::query()
            ->with('connection')
            ->where('crm_id', $crmId)
            ->where('external_id', $externalId)
            ->first();

        if (! $cached) {
            return $this->error('not_found', 'Заявка не найдена в кэше Единого окна.', 404);
        }

        if ($cached->connection?->type !== 'crm2_http') {
            return $this->error('forbidden', 'Заявка не является KP-Lead.', 403);
        }

        return $cached;
    }

    private function isFinal(DeskOrderCache $cached): bool
    {
        if ((string) $cached->status === 'closed') {
            return true;
        }

        return in_array(mb_strtolower((string) $cached->raw_status), [
            'completed', 'closed', 'done',
            'cancelled_cc', 'cancelled_city', 'canceled_cc', 'canceled_city',
            'rejected', 'refused',
        ], true);
    }

    /** @return array{id:int,name:string,city_ids:null,can_close:bool} */
    private function internalDeskUser(bool $canClose): array
    {
        return [
            'id' => 0,
            'name' => 'guild-desk-internal',
            'city_ids' => null,
            'can_close' => $canClose,
        ];
    }

    private function writeFailed(Throwable $e): JsonResponse
    {
        return $this->error('source_write_failed', KpHttpError::message($e), 502);
    }

    /** @param  array<string, mixed>  $extra */
    private function error(string $code, string $message, int $http, array $extra = []): JsonResponse
    {
        return response()->json(array_merge([
            'ok' => false,
            'code' => $code,
            'message' => $message,
        ], $extra), $http);
    }
}
