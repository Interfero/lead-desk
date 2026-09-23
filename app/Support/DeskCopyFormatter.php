<?php

namespace App\Support;

use App\Models\DeskOrderCache;

class DeskCopyFormatter
{
    /**
     * Формат как в kp-lead-centre refreshReqInfo (через запятую).
     * Переносы внутри описания сохраняем.
     *
     * Адрес в копировании — без квартиры (улица/дом), как до «Показать квартиру» в КП.
     * Полные данные (address_office) хранятся в кэше Единого окна отдельно.
     * $addressOverride — уже подготовленная строка улицы из контроллера.
     */
    public static function format(DeskOrderCache $order, ?string $addressOverride = null): string
    {
        $typeLabels = [
            'first' => 'Впервые',
            'new' => 'Впервые',
            'repeat' => 'Повтор',
            'warranty' => 'Гарантия',
        ];

        $parts = [];
        $parts[] = 'Заказ '.$order->external_id;
        $parts[] = $typeLabels[$order->order_type] ?? ($order->order_type ?: null);

        $streetOrFull = $addressOverride !== null
            ? trim($addressOverride)
            : DeskAddressOffice::streetAddressForDisplay(
                (string) ($order->address ?? ''),
                $order->address_office
            );

        $address = trim(implode(' ', array_filter([
            // если адрес уже содержит город — не дублируем
            ($streetOrFull !== '' && $order->city_name && ! str_contains($streetOrFull, $order->city_name))
                ? $order->city_name
                : null,
            $streetOrFull !== '' ? $streetOrFull : ($order->city_name ?: null),
        ])));
        if ($address !== '') {
            $parts[] = $address;
        }

        $desc = trim((string) ($order->description ?: $order->comments ?: ''));
        if ($desc !== '') {
            // убрать кавычки как в kp, но переносы строк оставить
            $desc = str_replace(['"', "'"], ' ', $desc);
            $desc = preg_replace("/[ \t]+/u", ' ', $desc) ?? $desc;
            $parts[] = trim($desc);
        }

        if (filled($order->client_name)) {
            $parts[] = trim((string) $order->client_name);
        }
        if (filled($order->client_age)) {
            $parts[] = trim((string) $order->client_age);
        }
        // Телефон в копирование не включаем (по требованию).

        $when = $order->call_at_local?->format('d-m-Y H:i')
            ?: $order->call_at_local?->format('d.m.Y H:i');
        if ($when) {
            $parts[] = $when;
        }

        if (filled($order->rk)) {
            $rkLine = 'РК: '.trim((string) $order->rk);
            if (filled($order->rk_url)) {
                $rkLine .= ' '.trim((string) $order->rk_url);
            }
            $parts[] = $rkLine;
        }

        if ($order->is_noncore) {
            $parts[] = 'непрофильная';
        }

        return implode(', ', array_values(array_filter($parts, fn ($p) => $p !== null && $p !== '')));
    }
}
