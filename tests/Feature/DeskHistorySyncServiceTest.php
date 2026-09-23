<?php

namespace Tests\Feature;

use App\Models\Crm2CityCredential;
use App\Models\CrmConnection;
use App\Models\DeskOrderCache;
use App\Models\DeskHistorySyncRun;
use App\Adapters\Crm2HttpAdapter;
use App\Services\DeskSyncService;
use App\Services\DeskHistorySyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeskHistorySyncServiceTest extends TestCase
{
    use RefreshDatabase;

    private CrmConnection $crm;

    private Crm2CityCredential $credential;

    private DeskHistorySyncService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->crm = CrmConnection::query()->create([
            'name' => 'kp-lead-centre',
            'type' => 'crm2_http',
            'base_url' => 'https://kp.test',
            'status' => 'active',
        ]);
        $this->credential = Crm2CityCredential::query()->create([
            'city_id' => 14,
            'city_name' => 'Псков',
            'login' => 'test@example.test',
            'password' => 'password',
            'status' => 'active',
        ]);
        Crm2CityCredential::query()->create([
            'city_id' => 15,
            'city_name' => 'Псковский район',
            'login' => 'test-15@example.test',
            'password' => 'password',
            'status' => 'active',
        ]);

        $this->service = app(DeskHistorySyncService::class);
    }

    public function test_start_manual_run_reuses_an_active_city_run(): void
    {
        $first = $this->service->startManualRun(14, 30, 7);
        $second = $this->service->startManualRun(14, 30, 8);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(30, $first->days);
        $this->assertSame(DeskHistorySyncRun::STATE_QUEUED, $first->state);
        $this->assertDatabaseCount('desk_history_sync_runs', 1);
    }

    public function test_worker_upserts_a_history_page_without_deleting_existing_open_cache_rows(): void
    {
        DeskOrderCache::query()->create([
            'crm_id' => $this->crm->id,
            'city_id' => 14,
            'external_id' => 'keep-open',
            'status' => 'in_progress',
            'raw_status' => 'in_progress',
        ]);
        $run = DeskHistorySyncRun::query()->create([
            'crm_id' => $this->crm->id,
            'city_id' => 14,
            'days' => 30,
            'period_from' => '2026-08-19',
            'period_to' => '2026-09-17',
            'next_page' => 1,
            'state' => DeskHistorySyncRun::STATE_QUEUED,
        ]);
        $adapter = new class($this->crm, $this->credential) extends Crm2HttpAdapter {
            public function authenticate(): void {}

            public function fetchHistoryPage(\DateTimeInterface $from, \DateTimeInterface $to, int $page): array
            {
                return [
                    'orders' => [[
                        'external_id' => 'closed-1002',
                        'raw_status' => 'completed',
                        'city_id' => 14,
                        'city_name' => 'Псков',
                    ]],
                    'next_page' => null,
                ];
            }
        };
        $service = new class(app(DeskSyncService::class), $adapter) extends DeskHistorySyncService {
            public function __construct(DeskSyncService $sync, private Crm2HttpAdapter $adapter)
            {
                parent::__construct($sync);
            }

            protected function makeHistoryAdapter(CrmConnection $crm, Crm2CityCredential $credential): Crm2HttpAdapter
            {
                return $this->adapter;
            }
        };

        $service->processQueuedPages(1);

        $this->assertDatabaseHas('orders_cache', ['external_id' => 'keep-open']);
        $this->assertDatabaseHas('orders_cache', ['external_id' => 'closed-1002', 'raw_status' => 'completed']);
        $this->assertDatabaseHas('desk_history_sync_runs', [
            'id' => $run->id,
            'state' => DeskHistorySyncRun::STATE_COMPLETED,
            'pages_processed' => 1,
        ]);
    }

    public function test_daily_queue_creates_one_7_day_run_only_for_history_enabled_cities(): void
    {
        $this->createHistoryRun(14, DeskHistorySyncRun::STATE_COMPLETED, false);
        $this->createHistoryRun(15, DeskHistorySyncRun::STATE_COMPLETED, false);
        $this->createHistoryRun(15, DeskHistorySyncRun::STATE_QUEUED, true);

        $this->assertSame(1, $this->service->queueDailyRuns());
        $this->assertDatabaseHas('desk_history_sync_runs', [
            'city_id' => 14,
            'days' => 7,
            'automatic' => true,
            'state' => DeskHistorySyncRun::STATE_QUEUED,
        ]);
        $this->assertDatabaseCount('desk_history_sync_runs', 4);
    }

    private function createHistoryRun(int $cityId, string $state, bool $automatic): DeskHistorySyncRun
    {
        return DeskHistorySyncRun::query()->create([
            'crm_id' => $this->crm->id,
            'city_id' => $cityId,
            'days' => $automatic ? 7 : 30,
            'period_from' => '2026-08-19',
            'period_to' => '2026-09-17',
            'state' => $state,
            'automatic' => $automatic,
            'finished_at' => $state === DeskHistorySyncRun::STATE_COMPLETED ? now() : null,
        ]);
    }
}
