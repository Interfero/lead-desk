<?php

namespace App\Support;

/**
 * Список КП даёт улицу/время, но не описание и не кв.
 * Полную карточку нужно дожимать в кэш, пока нет id клиента или описания.
 */
final class DeskKpHydration
{
    public static function isIncomplete(
        ?string $customerExternalId,
        ?string $description = null,
        ?string $comments = null,
    ): bool {
        return self::blank($customerExternalId);
    }

    private static function blank(?string $value): bool
    {
        return $value === null || trim($value) === '';
    }
}
