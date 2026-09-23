<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeskOrderCache extends Model
{
    protected $table = 'orders_cache';

    public const CLOSED_RAW = ['completed', 'cancelled_cc', 'cancelled_city', 'rejected'];

    protected $fillable = [
        'crm_id', 'external_id', 'city_id', 'city_name', 'rk', 'rk_url', 'status', 'raw_status',
        'client_name', 'client_age', 'customer_external_id', 'phone', 'phone_norm', 'address', 'address_norm', 'address_office', 'description', 'master_name', 'master_external_id',
        'total_amount', 'paid_amount', 'parts_amount', 'prepayment', 'with_bso', 'with_zip', 'created_at_local',
        'call_at_local', 'timezone', 'updated_at_local', 'order_type', 'order_core', 'is_noncore',
        'is_long_trip', 'is_satellite', 'is_partner_order', 'needs_feedback', 'gm_status',
        'comments', 'documents', 'client_history', 'priority', 'row_highlight',
        'fraud_status', 'fraud_note', 'fraud_checked_at',
        'hash', 'last_synced_at',
    ];

    protected $casts = [
        'documents' => 'array',
        'client_history' => 'array',
        'is_noncore' => 'boolean',
        'is_long_trip' => 'boolean',
        'is_satellite' => 'boolean',
        'is_partner_order' => 'boolean',
        'needs_feedback' => 'boolean',
        'with_bso' => 'integer',
        'with_zip' => 'integer',
        'prepayment' => 'integer',
        'created_at_local' => 'datetime',
        'call_at_local' => 'datetime',
        'updated_at_local' => 'datetime',
        'fraud_checked_at' => 'datetime',
        'last_synced_at' => 'datetime',
    ];

    public function connection(): BelongsTo
    {
        return $this->belongsTo(CrmConnection::class, 'crm_id');
    }

    /**
     * Красная подсветка времени только для свежей просрочки.
     * Старые «Ожидание» (сутки+) не должны гореть красным в списке гендира.
     */
    public const CALL_LATE_URGENT_HOURS = 48;

    public function callTimeUrgencyClass(?\DateTimeInterface $now = null): string
    {
        if ($this->raw_status !== 'pending' || ! $this->call_at_local) {
            return '';
        }

        $nowTs = ($now ?? now())->getTimestamp();
        $seconds = $this->call_at_local->getTimestamp() - $nowTs;
        $maxLate = self::CALL_LATE_URGENT_HOURS * 3600;

        if ($seconds < 0) {
            return $seconds >= -$maxLate ? 'time-late' : '';
        }

        if ($seconds <= 3600) {
            return 'blink-red';
        }

        return '';
    }

    /** Как в CRM: приоритет статуса → время заявки → ID */
    public static function listStatusSortPriority(): array
    {
        return [
            'pending' => 1,
            'on_way' => 2,
            'in_progress' => 3,
            'in_progress_sd' => 4,
            'review' => 5,
            'completed' => 5,
            'cancelled_cc' => 5,
            'cancelled_city' => 5,
            'rejected' => 5,
            'callback' => 6,
            'not_processed' => 7,
        ];
    }

    public function scopeOrderByListDefault(Builder $query, string $datetimeDir = 'asc'): Builder
    {
        $datetimeDir = strtolower($datetimeDir) === 'desc' ? 'desc' : 'asc';

        $when = [];
        $bindings = [];
        foreach (self::listStatusSortPriority() as $status => $priority) {
            $when[] = 'WHEN raw_status = ? THEN ?';
            $bindings[] = $status;
            $bindings[] = $priority;
        }

        $sql = 'CASE '.implode(' ', $when).' ELSE 99 END';
        // Просроченное «Ожидание» старше 48ч — в конец очереди (не мешают живым заявкам).
        $stalePendingSql = "CASE WHEN raw_status = 'pending' AND call_at_local IS NOT NULL AND call_at_local < ? THEN 45 ELSE ({$sql}) END";
        $staleCutoff = now()->subHours(self::CALL_LATE_URGENT_HOURS)->toDateTimeString();

        return $query
            ->orderByRaw($stalePendingSql.' ASC', array_merge([$staleCutoff], $bindings))
            ->orderByRaw('COALESCE(call_at_local, created_at_local) '.$datetimeDir)
            ->orderBy('external_id', $datetimeDir);
    }

    public static function unifiedStatusLabels(): array
    {
        return [
            'new' => 'Новые',
            'in_progress' => 'В работе',
            'on_way' => 'В пути',
            'ready' => 'К закрытию',
        ];
    }

    /** Статусы как в CRM — для фильтра */
    public static function rawStatusLabels(): array
    {
        return [
            'pending' => 'Ожидание',
            'callback' => 'Прозвон',
            'not_processed' => 'Не оформлена',
            'rejected' => 'Отказ',
            'on_way' => 'В пути',
            'in_progress' => 'В работе',
            'in_progress_sd' => 'В работе СД',
            'review' => 'Проверка',
            'completed' => 'Готов',
            'cancelled_cc' => 'Отмена КЦ',
            'cancelled_city' => 'Отмена Филиала',
        ];
    }

    public static function orderTypeLabels(): array
    {
        return [
            'first' => 'Впервые',
            'repeat' => 'Повтор',
            'warranty' => 'Гарантия',
        ];
    }
}
