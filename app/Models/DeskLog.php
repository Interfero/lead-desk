<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeskLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'crm_id', 'level', 'action', 'external_id', 'message', 'context', 'created_at',
    ];

    protected $casts = [
        'context' => 'array',
        'created_at' => 'datetime',
    ];

    public function connection(): BelongsTo
    {
        return $this->belongsTo(CrmConnection::class, 'crm_id');
    }

    public static function write(
        string $level,
        string $message,
        ?int $crmId = null,
        ?string $action = null,
        ?string $externalId = null,
        ?array $context = null
    ): self {
        return self::query()->create([
            'crm_id' => $crmId,
            'level' => $level,
            'action' => $action,
            'external_id' => $externalId,
            'message' => mb_substr($message, 0, 5000),
            'context' => $context,
            'created_at' => now(),
        ]);
    }
}
