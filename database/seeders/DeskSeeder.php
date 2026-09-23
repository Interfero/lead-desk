<?php

namespace Database\Seeders;

use App\Models\CrmConnection;
use App\Models\DeskStat;
use App\Models\DeskStatusMapping;
use Illuminate\Database\Seeder;

class DeskSeeder extends Seeder
{
    public function run(): void
    {
        $crm1 = CrmConnection::query()->updateOrCreate(
            ['type' => 'crm1_api'],
            [
                'name' => 'Lead Control',
                'base_url' => config('desk.crm1_base_url'),
                'status' => 'active',
                'sync_interval' => 60,
                'timeout' => 5,
                'config' => [
                    'api_token' => config('desk.crm1_token'),
                ],
            ]
        );

        CrmConnection::query()->updateOrCreate(
            ['type' => 'crm2_http'],
            [
                'name' => 'kp-lead-centre',
                'base_url' => config('desk.crm2_base_url', 'https://kp-lead-centre.ru'),
                'status' => 'inactive',
                'timeout' => 15,
                'config' => [
                    'orders_path' => '/admin/domain/customer-request/index',
                    'order_path' => '/admin/domain/customer-request/update?id={id}',
                ],
            ]
        );

        $rows = [
            ['callback', 'new', false, 10],
            ['not_processed', 'new', false, 20],
            ['pending', 'new', false, 30],
            ['on_way', 'on_way', false, 40],
            ['in_progress', 'in_progress', false, 50],
            ['in_progress_sd', 'in_progress', false, 60],
            ['review', 'ready', false, 70],
            ['completed', 'closed', true, 100],
            ['cancelled_cc', 'closed', true, 110],
            ['cancelled_city', 'closed', true, 120],
            ['rejected', 'closed', true, 130],
        ];

        foreach ($rows as [$code, $unified, $closed, $order]) {
            DeskStatusMapping::query()->updateOrCreate(
                ['crm_id' => $crm1->id, 'crm_status_code' => $code],
                ['unified_status' => $unified, 'is_closed' => $closed, 'display_order' => $order]
            );
        }

        $crm2 = CrmConnection::query()->where('type', 'crm2_http')->first();
        if ($crm2) {
            foreach ($rows as [$code, $unified, $closed, $order]) {
                DeskStatusMapping::query()->updateOrCreate(
                    ['crm_id' => $crm2->id, 'crm_status_code' => $code],
                    ['unified_status' => $unified, 'is_closed' => $closed, 'display_order' => $order]
                );
            }
        }

        DeskStat::query()->firstOrCreate(['key' => 'closed_via_desk'], ['value' => 0]);
    }
}
