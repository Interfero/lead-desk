<?php

namespace App\Console\Commands;

use App\Models\Crm2CityCredential;
use App\Models\CrmConnection;
use App\Services\DeskSyncService;
use Illuminate\Console\Command;
use Throwable;

class DeskSyncCommand extends Command
{
    protected $signature = 'desk:sync {--full} {--type= : Only crm1_api or crm2_http}';

    protected $description = 'Sync orders into desk cache';

    public function handle(DeskSyncService $sync): int
    {
        $onlyType = $this->option('type');
        $failed = 0;

        $query = CrmConnection::query()->whereIn('status', ['active', 'error', 'inactive']);
        if (in_array($onlyType, ['crm1_api', 'crm2_http'], true)) {
            $query->where('type', $onlyType);
        }

        foreach ($query->get() as $crm) {
            if ($crm->type === 'crm2_http') {
                // KP отдельно по расписанию everyTwoMinutes
                if ($onlyType !== 'crm2_http') {
                    continue;
                }
                $hasCreds = Crm2CityCredential::query()
                    ->whereNotNull('login')
                    ->whereNotNull('password')
                    ->exists();
                if (! $hasCreds) {
                    $this->line($crm->name.' — пропуск (нет доступов филиалов)');
                    continue;
                }
            } elseif ($crm->status === 'inactive') {
                continue;
            }

            $this->info($crm->name);
            try {
                $r = $sync->syncConnection($crm, (bool) $this->option('full'));
                $this->line("  +{$r['upserted']} -{$r['removed']}");
            } catch (Throwable $e) {
                $failed++;
                $this->error('  '.$e->getMessage());
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
