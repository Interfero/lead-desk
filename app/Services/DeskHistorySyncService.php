<?php

namespace App\Services;

use App\Adapters\Crm2HttpAdapter;
use App\Models\Crm2CityCredential;
use App\Models\CrmConnection;
use App\Models\DeskHistorySyncRun;
use App\Support\KpHttpError;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class DeskHistorySyncService
{
    /** @var list<int> */
    private const ALLOWED_DAYS = [7, 30, 90, 365, 730];

    public function __construct(
        protected DeskSyncService $sync
    ) {}

    public function startManualRun(int $cityId, int $days, int $userId): DeskHistorySyncRun
    {
        if (! in_array($days, self::ALLOWED_DAYS, true)) {
            throw new RuntimeException('Допустимый период истории KP: 7, 30, 90 дней, 1 год или 2 года.');
        }

        $crm = CrmConnection::query()->where('type', 'crm2_http')->first();
        if (! $crm) {
            throw new RuntimeException('Подключение kp-lead-centre не настроено.');
        }

        $credential = Crm2CityCredential::query()->where('city_id', $cityId)->first();
        if (! $credential?->hasCredentials()) {
            throw new RuntimeException('Сначала сохраните логин и пароль');
        }

        return DB::transaction(function () use ($crm, $cityId, $days, $userId) {
            $active = DeskHistorySyncRun::query()
                ->where('crm_id', $crm->id)
                ->where('city_id', $cityId)
                ->whereIn('state', [DeskHistorySyncRun::STATE_QUEUED, DeskHistorySyncRun::STATE_RUNNING])
                ->lockForUpdate()
                ->first();
            if ($active) {
                return $active;
            }

            $today = now()->timezone('Europe/Moscow')->startOfDay();

            return DeskHistorySyncRun::query()->create([
                'crm_id' => $crm->id,
                'city_id' => $cityId,
                'days' => $days,
                'period_from' => $today->copy()->subDays($days - 1)->toDateString(),
                'period_to' => $today->toDateString(),
                'state' => DeskHistorySyncRun::STATE_QUEUED,
                'automatic' => false,
                'created_by' => $userId ?: null,
            ]);
        });
    }

    public function processQueuedPages(int $limit = 10): int
    {
        $processed = 0;
        $limit = max(1, min(10, $limit));

        while ($processed < $limit) {
            $run = $this->claimNextRun();
            if (! $run) {
                break;
            }

            $lock = Cache::lock('desk-history-sync-run-'.$run->id, 120);
            if (! $lock->get()) {
                break;
            }

            try {
                if ($processed > 0) {
                    usleep(5_000_000);
                }
                $this->processRunPage($run);
            } catch (Throwable $e) {
                $run->forceFill([
                    'state' => DeskHistorySyncRun::STATE_FAILED,
                    'error' => KpHttpError::message($e),
                    'finished_at' => now(),
                    'last_processed_at' => now(),
                ])->save();
            } finally {
                $lock->release();
            }

            $processed++;
        }

        return $processed;
    }

    public function queueDailyRuns(): int
    {
        $enabledCities = DeskHistorySyncRun::query()
            ->where('automatic', false)
            ->where('state', DeskHistorySyncRun::STATE_COMPLETED)
            ->orderBy('crm_id')
            ->orderBy('city_id')
            ->get(['crm_id', 'city_id'])
            ->unique(fn (DeskHistorySyncRun $run) => $run->crm_id.':'.$run->city_id);

        $created = 0;
        foreach ($enabledCities as $enabled) {
            $credential = Crm2CityCredential::query()->where('city_id', $enabled->city_id)->first();
            if (! $credential?->hasCredentials()) {
                continue;
            }

            $wasCreated = DB::transaction(function () use ($enabled): bool {
                $active = DeskHistorySyncRun::query()
                    ->where('crm_id', $enabled->crm_id)
                    ->where('city_id', $enabled->city_id)
                    ->whereIn('state', [DeskHistorySyncRun::STATE_QUEUED, DeskHistorySyncRun::STATE_RUNNING])
                    ->lockForUpdate()
                    ->exists();
                if ($active) {
                    return false;
                }

                $today = now()->timezone('Europe/Moscow')->startOfDay();
                DeskHistorySyncRun::query()->create([
                    'crm_id' => $enabled->crm_id,
                    'city_id' => $enabled->city_id,
                    'days' => 7,
                    'period_from' => $today->copy()->subDays(6)->toDateString(),
                    'period_to' => $today->toDateString(),
                    'state' => DeskHistorySyncRun::STATE_QUEUED,
                    'automatic' => true,
                ]);

                return true;
            });

            if ($wasCreated) {
                $created++;
            }
        }

        return $created;
    }

    protected function claimNextRun(): ?DeskHistorySyncRun
    {
        return DB::transaction(function () {
            $run = DeskHistorySyncRun::query()
                ->whereIn('state', [DeskHistorySyncRun::STATE_QUEUED, DeskHistorySyncRun::STATE_RUNNING])
                ->orderByRaw('CASE WHEN state = ? THEN 0 ELSE 1 END', [DeskHistorySyncRun::STATE_QUEUED])
                ->orderBy('last_processed_at')
                ->orderBy('id')
                ->lockForUpdate()
                ->first();
            if (! $run) {
                return null;
            }

            $run->forceFill([
                'state' => DeskHistorySyncRun::STATE_RUNNING,
                'started_at' => $run->started_at ?? now(),
                'error' => null,
            ])->save();

            return $run;
        });
    }

    protected function processRunPage(DeskHistorySyncRun $run): void
    {
        $crm = CrmConnection::query()->findOrFail($run->crm_id);
        $credential = Crm2CityCredential::query()->where('city_id', $run->city_id)->first();
        if (! $credential?->hasCredentials()) {
            throw new RuntimeException('Сначала сохраните логин и пароль');
        }

        $page = $this->makeHistoryAdapter($crm, $credential)->fetchHistoryPage(
            $run->period_from,
            $run->period_to,
            $run->next_page
        );
        $upserted = $this->sync->upsertHistoryOrders($crm, $page['orders'], (int) $run->city_id);
        $now = now();
        $attributes = [
            'pages_processed' => $run->pages_processed + 1,
            'orders_upserted' => $run->orders_upserted + $upserted,
            'last_processed_at' => $now,
            'error' => null,
        ];
        if ($page['next_page'] === null) {
            $attributes['state'] = DeskHistorySyncRun::STATE_COMPLETED;
            $attributes['finished_at'] = $now;
        } else {
            $attributes['state'] = DeskHistorySyncRun::STATE_RUNNING;
            $attributes['next_page'] = $page['next_page'];
        }

        $run->forceFill($attributes)->save();
    }

    protected function makeHistoryAdapter(CrmConnection $crm, Crm2CityCredential $credential): Crm2HttpAdapter
    {
        return new Crm2HttpAdapter($crm, $credential);
    }
}
