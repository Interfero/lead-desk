<?php

namespace Tests\Feature;

use App\Models\CrmConnection;
use App\Models\DeskOrderCache;
use App\Services\DeskSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeskSyncMasterPreserveTest extends TestCase
{
    use RefreshDatabase;

    private CrmConnection $crm;

    private DeskSyncService $sync;

    protected function setUp(): void
    {
        parent::setUp();

        $this->crm = CrmConnection::query()->create([
            'name' => 'kp-lead-centre',
            'type' => 'crm2_http',
            'base_url' => 'https://kp.test',
            'status' => 'active',
        ]);
        $this->sync = app(DeskSyncService::class);
    }

    public function test_history_list_with_empty_master_keeps_cached_master(): void
    {
        DeskOrderCache::query()->create([
            'crm_id' => $this->crm->id,
            'city_id' => 14,
            'external_id' => '2641151',
            'status' => 'closed',
            'raw_status' => 'completed',
            'master_name' => 'Миколиков Владислав',
            'master_external_id' => '19854',
            'total_amount' => 3010,
            'paid_amount' => 3010,
            'parts_amount' => 0,
        ]);

        $this->sync->upsertHistoryOrders($this->crm, [[
            'external_id' => '2641151',
            'raw_status' => 'completed',
            'city_id' => 14,
            'city_name' => 'Псков',
            'master_name' => null,
            'total_amount' => 3010,
        ]], 14);

        $row = DeskOrderCache::query()->where('external_id', '2641151')->first();
        $this->assertNotNull($row);
        $this->assertSame('Миколиков Владислав', $row->master_name);
        $this->assertSame('19854', (string) $row->master_external_id);
    }

    public function test_list_reassignment_by_name_clears_stale_employee_id(): void
    {
        DeskOrderCache::query()->create([
            'crm_id' => $this->crm->id,
            'city_id' => 14,
            'external_id' => '2645892',
            'status' => 'closed',
            'raw_status' => 'completed',
            'master_name' => 'Миколиков Владислав',
            'master_external_id' => '19854',
            'total_amount' => 3010,
        ]);

        $this->sync->upsertHistoryOrders($this->crm, [[
            'external_id' => '2645892',
            'raw_status' => 'completed',
            'city_id' => 14,
            'master_name' => 'Габдуллин Тимур',
            'total_amount' => 3010,
        ]], 14);

        $row = DeskOrderCache::query()->where('external_id', '2645892')->first();
        $this->assertSame('Габдуллин Тимур', $row->master_name);
        $this->assertNull($row->master_external_id);
    }

    public function test_detail_payload_updates_master_id_and_parts(): void
    {
        $cached = DeskOrderCache::query()->create([
            'crm_id' => $this->crm->id,
            'city_id' => 14,
            'external_id' => '2611087',
            'status' => 'closed',
            'raw_status' => 'completed',
            'master_name' => 'Миколиков Владислав',
            'master_external_id' => null,
            'total_amount' => 18710,
            'paid_amount' => null,
            'parts_amount' => null,
            'needs_feedback' => false,
        ]);

        $this->sync->applyLivePayload($cached, [
            'raw_status' => 'completed',
            'master_name' => 'Миколиков Владислав',
            'master_external_id' => '19854',
            'master_id' => '19854',
            'paid_amount' => 20000,
            'parts_amount' => 1290,
            'total_amount' => 18710,
        ]);

        $row = $cached->fresh();
        $this->assertSame('19854', (string) $row->master_external_id);
        $this->assertSame(1290, (int) $row->parts_amount);
        $this->assertSame(20000, (int) $row->paid_amount);
    }
}
