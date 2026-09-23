<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeskStatusMapping extends Model
{
    protected $fillable = [
        'crm_id', 'crm_status_code', 'unified_status', 'is_closed', 'display_order',
    ];

    protected $casts = [
        'is_closed' => 'boolean',
    ];

    public function connection(): BelongsTo
    {
        return $this->belongsTo(CrmConnection::class, 'crm_id');
    }
}
