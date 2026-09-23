<?php

namespace App\Console\Commands;

use App\Services\DeskHistorySyncService;
use Illuminate\Console\Command;

class DeskHistorySyncQueueDailyCommand extends Command
{
    protected $signature = 'desk:history-sync:queue-daily';

    protected $description = 'Queue daily seven-day KP history reconciliations';

    public function handle(DeskHistorySyncService $history): int
    {
        $created = $history->queueDailyRuns();
        $this->line("Создано ежедневных задач истории KP: {$created}");

        return self::SUCCESS;
    }
}
