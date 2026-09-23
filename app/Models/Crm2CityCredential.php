<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Crm2CityCredential extends Model
{
    protected $fillable = [
        'city_id', 'city_name', 'login', 'password', 'status',
        'last_sync_at', 'last_error', 'updated_by',
    ];

    protected $casts = [
        'password' => 'encrypted',
        'last_sync_at' => 'datetime',
    ];

    protected $hidden = ['password'];

    public function hasCredentials(): bool
    {
        return filled($this->login) && filled($this->password);
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
