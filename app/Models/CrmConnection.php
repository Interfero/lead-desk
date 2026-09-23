<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CrmConnection extends Model
{
    protected $fillable = [
        'name', 'type', 'base_url', 'login', 'password', 'status',
        'last_sync_at', 'sync_interval', 'timeout', 'config', 'last_error',
    ];

    protected $casts = [
        'password' => 'encrypted',
        'config' => 'array',
        'last_sync_at' => 'datetime',
    ];

    protected $hidden = ['password'];

    public function statusMappings(): HasMany
    {
        return $this->hasMany(DeskStatusMapping::class, 'crm_id');
    }

    public function markError(string $message): void
    {
        $this->forceFill([
            'status' => 'error',
            'last_error' => mb_substr($message, 0, 2000),
        ])->save();
    }

    public function markSynced(): void
    {
        $this->forceFill([
            'status' => 'active',
            'last_sync_at' => now(),
            'last_error' => null,
        ])->save();
    }
}
