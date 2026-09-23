<?php

namespace App\Console\Commands;

use App\Models\DeskOrderCache;
use App\Services\DeskFraudService;
use Illuminate\Console\Command;

class DeskRecheckFraudCommand extends Command
{
    protected $signature = 'desk:recheck-fraud {--limit=3000}';

    protected $description = 'Пересчитать фрод (кросс-CRM) для заказов в кэше окна';

    public function handle(DeskFraudService $fraud): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $this->info("Backfill norms + fraud check (limit={$limit})…");

        $orders = DeskOrderCache::query()->orderByDesc('id')->limit($limit)->get();
        $result = $fraud->recheckMany($orders);

        $this->info("checked={$result['checked']} not_ok={$result['not_ok']}");

        return self::SUCCESS;
    }
}
