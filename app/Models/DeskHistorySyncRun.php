<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeskHistorySyncRun extends Model
{
    public const STATE_QUEUED = 'queued';
    public const STATE_RUNNING = 'running';
    public const STATE_COMPLETED = 'completed';
    public const STATE_FAILED = 'failed';

    protected $fillable = [
        'crm_id', 'city_id', 'days', 'period_from', 'period_to', 'next_page',
        'state', 'automatic', 'pages_processed', 'orders_upserted',
        'last_processed_at', 'error', 'created_by', 'started_at', 'finished_at',
    ];

    protected $casts = [
        'period_from' => 'date',
        'period_to' => 'date',
        'automatic' => 'boolean',
        'last_processed_at' => 'datetime',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function connection(): BelongsTo
    {
        return $this->belongsTo(CrmConnection::class, 'crm_id');
    }

    public function stateLabel(): string
    {
        return match ($this->state) {
            self::STATE_QUEUED => 'В очереди',
            self::STATE_RUNNING => 'Выполняется',
            self::STATE_COMPLETED => 'Готово',
            self::STATE_FAILED => 'Ошибка',
            default => 'Неизвестно',
        };
    }
}
