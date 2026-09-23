<?php

namespace App\Services;

use App\Models\DeskOrderCache;
use Illuminate\Support\Collection;

/**
 * Проверка на дубль заказа в другой CRM (фрод).
 *
 * Логика:
 * 1) при появлении/обновлении заказа — ищем совпадения в других CRM;
 * 2) сравнение по нормализованному телефону и адресу;
 * 3) совпадение + незакрытый заказ в другой CRM → not_ok;
 *    совпадение только с закрытыми («готов» и т.п.) или нет совпадений → ok;
 * 4) только индикация, на процесс не влияет.
 */
class DeskFraudService
{
    public const OK = 'ok';

    public const NOT_OK = 'not_ok';

    public const PENDING = 'pending';

    public const MIN_ADDRESS_LEN = 10;

    /** @return array{status: string, note: ?string} */
    public function check(DeskOrderCache $order): array
    {
        $phone = $this->normalizePhone((string) ($order->phone ?? ''));
        $addressRaw = trim((string) ($order->address ?? '').' '.((string) ($order->address_office ?? '')));
        $address = $this->normalizeAddress($addressRaw);

        $order->phone_norm = $phone !== '' ? $phone : null;
        $order->address_norm = $address !== '' ? $address : null;

        if ($phone === '' && ($address === '' || mb_strlen($address) < self::MIN_ADDRESS_LEN)) {
            return [
                'status' => self::OK,
                'note' => null,
            ];
        }

        $matches = $this->findMatchesInOtherCrms($order, $phone, $address);
        if ($matches->isEmpty()) {
            return [
                'status' => self::OK,
                'note' => null,
            ];
        }

        $open = $matches->filter(fn (DeskOrderCache $m) => ! $this->isClosed($m))->values();
        if ($open->isEmpty()) {
            return [
                'status' => self::OK,
                'note' => null,
            ];
        }

        $parts = $open->take(5)->map(function (DeskOrderCache $m) {
            $crm = $m->connection?->name ?: ('CRM#'.$m->crm_id);
            $st = DeskOrderCache::rawStatusLabels()[$m->raw_status]
                ?? DeskOrderCache::unifiedStatusLabels()[$m->status]
                ?? ($m->raw_status ?: $m->status ?: '?');

            return $crm.' #'.$m->external_id.' ('.$st.')';
        })->all();

        return [
            'status' => self::NOT_OK,
            'note' => 'Незакрытый заказ в другой CRM: '.implode('; ', $parts),
        ];
    }

    public function apply(DeskOrderCache $order, bool $force = false): DeskOrderCache
    {
        if (! $force && $order->fraud_status !== null && $order->fraud_checked_at) {
            return $order;
        }

        $result = $this->check($order);
        $order->forceFill([
            'phone_norm' => $order->phone_norm,
            'address_norm' => $order->address_norm,
            'fraud_status' => $result['status'],
            'fraud_note' => $result['note'],
            'fraud_checked_at' => now(),
        ])->save();

        return $order;
    }

    /**
     * Пересчитать фрод для выборки (после деплоя / по кнопке).
     *
     * @param  iterable<DeskOrderCache>  $orders
     * @return array{checked: int, not_ok: int}
     */
    public function recheckMany(iterable $orders): array
    {
        $list = collect($orders)->values();
        // Два прохода: чтобы взаимные совпадения успели увидеть уже проставленные norms
        foreach ([1, 2] as $pass) {
            foreach ($list as $order) {
                $fresh = $order->fresh() ?? $order;
                $this->apply($fresh, true);
                $order->fraud_status = $fresh->fraud_status;
                $order->fraud_note = $fresh->fraud_note;
            }
        }

        $checked = $list->count();
        $not_ok = $list->filter(fn ($o) => $o->fraud_status === self::NOT_OK)->count();

        return compact('checked', 'not_ok');
    }

    /**
     * @return Collection<int, DeskOrderCache>
     */
    protected function findMatchesInOtherCrms(DeskOrderCache $order, string $phone, string $address): Collection
    {
        $query = DeskOrderCache::query()
            ->with('connection')
            ->where('crm_id', '!=', (int) $order->crm_id)
            ->when($order->id, fn ($q) => $q->where('id', '!=', (int) $order->id));

        $query->where(function ($q) use ($phone, $address) {
            $has = false;
            if ($phone !== '') {
                $q->orWhere('phone_norm', $phone);
                $has = true;
                // запасной путь, пока не все строки с phone_norm
                $q->orWhere(function ($q2) use ($phone) {
                    $q2->whereNull('phone_norm')
                        ->whereNotNull('phone')
                        ->where('phone', '!=', '')
                        ->whereRaw(
                            "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(phone,' ',''),'-',''),'(',''),')',''),'+',''),'.','') LIKE ?",
                            ['%'.substr($phone, -10).'%']
                        );
                });
            }
            if ($address !== '' && mb_strlen($address) >= self::MIN_ADDRESS_LEN) {
                if ($has) {
                    $q->orWhere('address_norm', $address);
                } else {
                    $q->where('address_norm', $address);
                }
            }
        });

        return $query->orderByDesc('id')->limit(40)->get();
    }

    public function isClosed(DeskOrderCache $order): bool
    {
        if (in_array((string) $order->raw_status, DeskOrderCache::CLOSED_RAW, true)) {
            return true;
        }

        return (string) $order->status === 'closed';
    }

    public function normalizePhone(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        if ($digits === '') {
            return '';
        }
        if (strlen($digits) === 11 && ($digits[0] === '8' || $digits[0] === '7')) {
            $digits = '7'.substr($digits, 1);
        } elseif (strlen($digits) === 10) {
            $digits = '7'.$digits;
        }

        if (strlen($digits) < 10) {
            return '';
        }

        return $digits;
    }

    public function normalizeAddress(string $address): string
    {
        $a = mb_strtolower(trim($address));
        if ($a === '') {
            return '';
        }
        $a = str_replace(['ё'], ['е'], $a);
        $a = preg_replace('/\s+/u', ' ', $a) ?? $a;
        $a = preg_replace('/[.,;:#№«»"\']+/u', ' ', $a) ?? $a;
        $replacements = [
            'улица ' => 'ул ',
            'ул. ' => 'ул ',
            'проспект ' => 'пр ',
            'пр-т ' => 'пр ',
            'пр. ' => 'пр ',
            'переулок ' => 'пер ',
            'пер. ' => 'пер ',
            'дом ' => 'д ',
            'д. ' => 'д ',
            'квартира ' => 'кв ',
            'кв. ' => 'кв ',
            'корпус ' => 'к ',
            'корп ' => 'к ',
            'корп. ' => 'к ',
            'строение ' => 'стр ',
            'стр. ' => 'стр ',
        ];
        foreach ($replacements as $from => $to) {
            $a = str_replace($from, $to, $a);
        }
        $a = preg_replace('/\s+/u', ' ', $a) ?? $a;

        return trim($a);
    }

    public static function label(?string $status): string
    {
        return match ($status) {
            self::OK => 'ок',
            self::NOT_OK => 'не ок',
            self::PENDING => '…',
            default => '—',
        };
    }
}
