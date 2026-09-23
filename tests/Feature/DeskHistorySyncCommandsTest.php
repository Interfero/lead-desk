<?php

namespace Tests\Feature;

use App\Services\DeskHistorySyncService;
use App\Services\DeskSyncService;
use Tests\TestCase;

class DeskHistorySyncCommandsTest extends TestCase
{
    public function test_history_worker_processes_the_requested_bounded_number_of_pages(): void
    {
        $service = new class(app(DeskSyncService::class)) extends DeskHistorySyncService {
            public int $receivedLimit = 0;

            public function processQueuedPages(int $limit = 10): int
            {
                $this->receivedLimit = $limit;

                return 2;
            }
        };
        $this->app->instance(DeskHistorySyncService::class, $service);

        $this->artisan('desk:history-sync:work', ['--limit' => 2])
            ->expectsOutput('Обработано страниц истории KP: 2')
            ->assertExitCode(0);

        $this->assertSame(2, $service->receivedLimit);
    }
}
