<?php

namespace App\Support;

class DeskAccess
{
    /** @param array<string, mixed> $user */
    public static function canManageCrm2Settings(array $user): bool
    {
        $roles = $user['roles'] ?? [];

        return collect($roles)->intersect([
            'branch_head',
            'senior_manager',
            'manager',
            'regional_director',
            'general_director',
            'developer',
        ])->isNotEmpty();
    }

    /**
     * null = все города; иначе список city_id.
     *
     * @param  array<string, mixed>  $user
     * @return list<int>|null
     */
    public static function cityIds(array $user): ?array
    {
        $ids = $user['city_ids'] ?? null;
        if ($ids === null) {
            return null;
        }
        if (! is_array($ids)) {
            return [];
        }

        return array_values(array_map('intval', $ids));
    }

    /**
     * Города для UI-фильтра (из SSO или fallback).
     *
     * @param  array<string, mixed>  $user
     * @return list<array{id:int,name:string,label:string,is_satellite:bool}>
     */
    public static function citiesForFilter(array $user): array
    {
        $fromSso = $user['cities'] ?? null;
        if (is_array($fromSso) && $fromSso !== []) {
            $out = [];
            foreach ($fromSso as $c) {
                if (! is_array($c) || empty($c['id'])) {
                    continue;
                }
                $out[] = [
                    'id' => (int) $c['id'],
                    'name' => (string) ($c['name'] ?? ('#'.$c['id'])),
                    'label' => (string) ($c['label'] ?? $c['name'] ?? ('#'.$c['id'])),
                    'is_satellite' => (bool) ($c['is_satellite'] ?? false),
                ];
            }

            return $out;
        }

        return [];
    }

    /** @param array<string, mixed> $user */
    public static function canAccessCity(array $user, int $cityId): bool
    {
        $ids = self::cityIds($user);
        if ($ids === null) {
            return true;
        }

        return in_array($cityId, $ids, true);
    }
}
