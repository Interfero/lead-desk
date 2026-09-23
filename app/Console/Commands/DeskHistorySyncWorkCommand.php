<?php

namespace App\Console\Commands;

use App\Services\DeskHistorySyncService;
use Illuminate\Console\Command;

class DeskHistorySyncWorkCommand extends Command
{
    protected $signature = 'desk:history-sync:work {--limit=10}';

    protected $description = 'Process bounded KP history synchronization pages';

    public function handle(DeskHistorySyncService $history): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => 10],
        ]);
        if ($limit === false) {
            $this->error('Лимит страниц истории KP должен быть от 1 до 10.');

            return self::FAILURE;
        }

        $processed = $history->processQueuedPages($limit);
        $this->line("Обработано страниц истории KP: {$processed}");

        return self::SUCCESS;
    }
}
