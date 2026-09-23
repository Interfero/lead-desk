<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DeskStat extends Model
{
    protected $fillable = ['key', 'value'];

    protected $casts = ['value' => 'integer'];

    public static function getValue(string $key): int
    {
        return (int) (self::query()->where('key', $key)->value('value') ?? 0);
    }

    public static function incrementKey(string $key, int $by = 1): void
    {
        $row = self::query()->firstOrCreate(['key' => $key], ['value' => 0]);
        $row->increment('value', $by);
    }
}
