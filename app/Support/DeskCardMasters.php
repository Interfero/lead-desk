<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;

class DeskCardMasters
{
    public const TTL_MINUTES = 45;

    public static function key(int $crmId, int $cityId): string
    {
        return 'desk-masters:'.$crmId.':'.$cityId;
    }

    /**
     * @param  list<array{id?:string,name?:string}>  $masters
     */
    public static function remember(int $crmId, ?int $cityId, array $masters, bool $allowEmpty = false): void
    {
        if ($cityId === null || $cityId <= 0) {
            return;
        }
        if ($masters === [] && ! $allowEmpty) {
            return;
        }

        Cache::put(self::key($crmId, $cityId), array_values($masters), now()->addMinutes(self::TTL_MINUTES));
    }

    /**
     * Активные мастера из выгрузки КП → список для селекта карточки.
     *
     * @param  list<array<string, mixed>>  $masters
     * @return list<array{id:string,name:string}>
     */
    public static function fromKpRows(array $masters): array
    {
        $out = [];
        foreach ($masters as $row) {
            if (! (bool) ($row['is_active'] ?? true)) {
                continue;
            }
            $id = (string) ($row['kp_employee_id'] ?? $row['id'] ?? '');
            if ($id === '' || $id === '0') {
                continue;
            }
            $name = trim((string) ($row['name'] ?? ''));
            $out[] = [
                'id' => $id,
                'name' => $name !== '' ? $name : ('#'.$id),
            ];
        }

        return $out;
    }

    /**
     * Объединить два списка по id (живой select КП не должен выкидывать полный список синка).
     *
     * @param  list<array{id?:string,name?:string}>  $a
     * @param  list<array{id?:string,name?:string}>  $b
     * @return list<array{id:string,name:string}>
     */
    public static function merge(array $a, array $b): array
    {
        $byId = [];
        foreach (array_merge($a, $b) as $row) {
            $id = (string) ($row['id'] ?? '');
            if ($id === '') {
                continue;
            }
            $name = trim((string) ($row['name'] ?? ''));
            if (! isset($byId[$id])) {
                $byId[$id] = [
                    'id' => $id,
                    'name' => $name !== '' ? $name : ('#'.$id),
                ];

                continue;
            }
            $current = (string) $byId[$id]['name'];
            if ($name !== '' && ($current === '' || str_starts_with($current, '#'))) {
                $byId[$id]['name'] = $name;
            }
        }

        return array_values($byId);
    }

    /**
     * @return list<array{id?:string,name?:string}>
     */
    public static function get(int $crmId, ?int $cityId): array
    {
        if ($cityId === null || $cityId <= 0) {
            return [];
        }

        $cached = Cache::get(self::key($crmId, $cityId));

        return is_array($cached) ? array_values($cached) : [];
    }

    /**
     * @param  list<array{id?:string,name?:string}>  $masters
     * @return list<array{id:string,name:string}>
     */
    public static function withCurrent(array $masters, ?string $id, ?string $name): array
    {
        $id = $id !== null && $id !== '' ? (string) $id : '';
        $name = $name !== null && trim($name) !== '' ? trim($name) : '';
        if ($id === '') {
            return array_values($masters);
        }

        foreach ($masters as $master) {
            if ((string) ($master['id'] ?? '') === $id) {
                return array_values($masters);
            }
        }

        array_unshift($masters, [
            'id' => $id,
            'name' => $name !== '' ? $name : ('#'.$id),
        ]);

        return array_values($masters);
    }
}
