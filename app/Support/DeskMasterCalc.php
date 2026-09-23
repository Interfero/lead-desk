<?php

namespace App\Support;

use App\Models\DeskOrderCache;

/**
 * Расчётка мастера по правилам CRM1 (доля от «чистыми»).
 */
class DeskMasterCalc
{
    /**
     * @return array{
     *   paid:int,parts:int,net_amount:int,master_percent:int,
     *   master_salary:int,amount_to_pay:int,order_type:?string,is_noncore:bool,source:string
     * }
     */
    public static function compute(
        int $paid,
        int $parts,
        ?string $orderType = null,
        bool $isNoncore = false,
        bool $isLongTrip = false,
        ?string $orderCore = null,
        bool $isSatellite = false,
        bool $isPartnerOrder = false,
    ): array {
        $net = max(0, $paid - max(0, $parts));
        $core = self::resolveOrderCore($orderCore, $isNoncore);
        $percent = self::percent($net, $orderType, $core, $isLongTrip, $isSatellite, $isPartnerOrder);
        $salary = (int) floor($net * $percent / 100);

        return [
            'paid' => $paid,
            'parts' => max(0, $parts),
            'net_amount' => $net,
            'master_percent' => $percent,
            'master_salary' => $salary,
            'amount_to_pay' => $net - $salary,
            'order_type' => $orderType,
            'is_noncore' => $core === 'non_core',
            'source' => 'crm1',
        ];
    }

    public static function computeFromCache(DeskOrderCache $cached): array
    {
        return self::compute(
            (int) ($cached->paid_amount ?? 0),
            (int) ($cached->parts_amount ?? 0),
            $cached->order_type,
            (bool) $cached->is_noncore,
            (bool) ($cached->is_long_trip ?? false),
            $cached->order_core,
            (bool) ($cached->is_satellite ?? false),
            (bool) ($cached->is_partner_order ?? false),
        );
    }

    public static function percent(
        int $net,
        ?string $orderType,
        ?string $orderCore = 'core',
        bool $isLongTrip = false,
        bool $isSatellite = false,
        bool $isPartnerOrder = false,
    ): int {
        if ($orderType === 'warranty' && $net <= 7500) {
            return 50;
        }
        if ($isLongTrip) {
            return 50;
        }
        if ($isSatellite) {
            return 50;
        }
        if ($orderCore === 'other') {
            return $isPartnerOrder ? 40 : 50;
        }
        if ($orderCore === 'non_core' && $net <= 7500) {
            return 40;
        }

        return match (true) {
            $net <= 2500 => 25,
            $net <= 4500 => 30,
            $net <= 7500 => 35,
            $net <= 10500 => 40,
            $net <= 17000 => 45,
            default => 50,
        };
    }

    protected static function resolveOrderCore(?string $orderCore, bool $isNoncore): string
    {
        if ($orderCore !== null && $orderCore !== '') {
            return $orderCore;
        }

        return $isNoncore ? 'non_core' : 'core';
    }
}
