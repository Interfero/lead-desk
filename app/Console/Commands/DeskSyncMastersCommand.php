<?php

namespace App\Console\Commands;

use App\Models\Crm2CityCredential;
use App\Services\KpMasterSyncService;
use App\Support\KpHttpError;
use Illuminate\Console\Command;
use Throwable;

class DeskSyncMastersCommand extends Command
{
    protected $signature = 'desk:sync-masters';

    protected $description = 'Sync KP masters into CRM and desk card dropdown cache';

    public function handle(KpMasterSyncService $sync): int
    {
        $creds = Crm2CityCredential::query()
            ->orderBy('city_id')
            ->get()
            ->filter(fn (Crm2CityCredential $c) => $c->hasCredentials())
            ->values();

        if ($creds->isEmpty()) {
            $this->line('Нет учёток КП филиалов');

            return self::SUCCESS;
        }

        $failed = 0;
        foreach ($creds as $i => $cred) {
            if ($i > 0) {
                usleep(5_000_000);
            }

            $this->info('city '.$cred->city_id);
            try {
                $r = $sync->syncCity($cred);
                $this->line(
                    '  fetched='.$r['fetched']
                    .' created='.$r['created']
                    .' updated='.$r['updated']
                    .' deactivated='.$r['deactivated']
                );
            } catch (Throwable $e) {
                $failed++;
                $this->error('  '.KpHttpError::message($e));
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
