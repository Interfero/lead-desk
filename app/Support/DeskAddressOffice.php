<?php

namespace App\Support;

use Carbon\CarbonInterface;

class DeskAddressOffice
{
    public const REVEAL_MINUTES_BEFORE = 30;

    /**
     * Статусы визита: для GM (и internal reveal) кв. доступна даже если
     * до call_at ещё больше 30 мин (мастер уже «в пути» / «в работе»).
     * Desk UI кнопка по-прежнему только по таймеру canReveal().
     *
     * @var list<string>
     */
    public const VISIT_STATUSES = ['on_way', 'in_progress', 'in_progress_sd', 'sd'];

    /**
     * call_at_local — «стенные» часы города, а app.timezone на проде UTC.
     * Без TZ нельзя сравнивать naive UTC-wall с now() в UTC (лочит на +3ч).
     * KP/часть синка не пишут timezone → дефолт Москва (РФ).
     */
    public const DEFAULT_WALL_CLOCK_TIMEZONE = 'Europe/Moscow';

    public static function resolveTimezone(?string $timezone): string
    {
        $tz = is_string($timezone) ? trim($timezone) : '';

        return $tz !== '' ? $tz : self::DEFAULT_WALL_CLOCK_TIMEZONE;
    }

    public static function canReveal(?CarbonInterface $callAt, ?CarbonInterface $now = null, ?string $timezone = null): bool
    {
        if ($callAt === null) {
            return false;
        }

        $tz = self::resolveTimezone($timezone);
        $now ??= now($tz);
        $call = $callAt->copy()->shiftTimezone($tz);

        return $now->greaterThanOrEqualTo($call->copy()->subMinutes(self::REVEAL_MINUTES_BEFORE));
    }

    /**
     * Окно для мастера в гильдии: таймер Desk (30 мин) ИЛИ статус визита.
     */
    public static function canRevealForMaster(
        ?CarbonInterface $callAt,
        ?string $rawStatus,
        ?string $timezone = null,
        ?CarbonInterface $now = null,
    ): bool {
        $status = mb_strtolower(trim((string) $rawStatus));
        if (in_array($status, self::VISIT_STATUSES, true)) {
            return true;
        }

        return self::canReveal($callAt, $now, $timezone);
    }

    public static function revealAt(?CarbonInterface $callAt, ?string $timezone = null): ?CarbonInterface
    {
        if ($callAt === null) {
            return null;
        }

        $tz = self::resolveTimezone($timezone);
        $call = $callAt->copy()->shiftTimezone($tz);

        return $call->copy()->subMinutes(self::REVEAL_MINUTES_BEFORE);
    }

    /**
     * Токен дома в адресе КП: «28», «10А», «28/9», «69к3», «69 к. 3».
     * Старый шаблон `\d+[а-я]?` ломался на «69к3» и оставлял квартиру в хвосте.
     */
    private const HOUSE_TOKEN = '\d+(?:\s*\/\s*\d+)?[а-яa-zА-ЯA-Z]?(?:\s*[кk]\.?\s*\d+[а-яa-zА-ЯA-Z]?)?';

    /**
     * Адрес без кв/подъезда/этажа/домофона — как в КП до «Показать квартиру».
     * Полный адрес в БД не трогаем, только отображение.
     */
    public static function streetAddressForDisplay(?string $address, ?string $officeText = null): string
    {
        $out = trim((string) $address);
        if ($out === '') {
            return '';
        }

        $out = preg_replace('/,?\s*подъезд\s*:?\s*\S+/iu', '', $out) ?? $out;
        $out = preg_replace('/,?\s*этаж\s*:?\s*\S+/iu', '', $out) ?? $out;
        $out = preg_replace('/,?\s*домофон\s*:?\s*[^,]*/iu', '', $out) ?? $out;
        $out = preg_replace('/,?\s*кв(?:артира)?\.?\s*(?:\/\s*офис)?\s*:?\s*\S+/iu', '', $out) ?? $out;

        $flat = null;
        if (is_string($officeText) && preg_match('/кв\s*\/?\s*офис\s*:?\s*([^\s,]+)/iu', $officeText, $m)) {
            $flat = $m[1];
        }
        if ($flat !== null && $flat !== '') {
            $q = preg_quote($flat, '/');
            $out = preg_replace('/,\s*'.$q.'(?=\s*,|\s*$)/u', '', $out) ?? $out;
        } else {
            // «улица, 28/9, 32» / «улица, 69к3, 33» / «улица, 10А, 5» — число после дома = квартира
            $out = preg_replace(
                '/(,\s*'.self::HOUSE_TOKEN.')\s*,\s*\d+[а-яa-zА-ЯA-Z]?(?=\s*,|\s*$)/u',
                '$1',
                $out
            ) ?? $out;
        }

        $out = preg_replace('/\s*,\s*,+/u', ',', $out) ?? $out;
        $out = preg_replace('/\s+/u', ' ', $out) ?? $out;

        return trim($out, " \t,");
    }

    /**
     * Адрес для списка: улица/дом; кв/офис — за REVEAL_MINUTES_BEFORE до call_at_local.
     */
    public static function addressForDisplay(?string $address, ?string $officeText, ?CarbonInterface $callAt, ?string $timezone = null): string
    {
        $full = trim((string) $address);
        $street = self::streetAddressForDisplay($full, $officeText);
        if ($street === '' && $full !== '') {
            $street = $full;
        }

        if ($callAt === null || ! self::canReveal($callAt, null, $timezone)) {
            return $street;
        }

        $office = trim((string) $officeText);
        if ($office !== '') {
            return $street !== '' ? $street.', '.$office : $office;
        }

        return $full !== '' ? $full : $street;
    }

    /** Есть ли в строке признаки квартиры/подъезда (для кнопки даже до кэша office). */
    public static function addressLooksLikeHasOffice(?string $address): bool
    {
        $a = (string) $address;
        if ($a === '') {
            return false;
        }
        if (preg_match('/подъезд|этаж|домофон|кв(?:артира)?\.?\s*\/?\s*офис/iu', $a)) {
            return true;
        }

        // «…, 28/9, 32», «…, 69к3, 33», «…, 10А, 5»
        return (bool) preg_match('/,\s*'.self::HOUSE_TOKEN.'\s*,\s*\d+/u', $a);
    }
}
