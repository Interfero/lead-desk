<?php

namespace App\Services;

use App\Models\CrmConnection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Инкремент CRM1, если кэш устарел.
 * На открытии списка не блокируем ответ — cron уже каждую минуту.
 */
class DeskAutoSyncService
{
    public function __construct(
        protected DeskSyncService $sync
    ) {}

    /**
     * @return array<string, mixed>|null
     */
    public function syncOnVisitIfNeeded(Request $request): ?array
    {
        if (! config('desk.auto_sync_on_visit', true)) {
            return null;
        }

        if (! $request->isMethod('GET') || $request->ajax() || $request->expectsJson()) {
            return null;
        }

        $user = $request->session()->get('desk_user');
        if (! is_array($user) || empty($user['id'])) {
            return null;
        }

        return $this->syncCrm1IfStale();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function syncCrm1IfStale(): ?array
    {
        if (! config('desk.auto_sync_on_visit', true)) {
            return null;
        }

        $lock = Cache::lock('desk-auto-sync-crm1', 90);
        if (! $lock->get()) {
            return null;
        }

        try {
            $crm = CrmConnection::query()
                ->where('type', 'crm1_api')
                ->whereIn('status', ['active', 'error'])
                ->orderBy('id')
                ->first();

            if (! $crm) {
                return null;
            }

            $staleMinutes = max(1, (int) config('desk.auto_sync_stale_minutes', 3));
            $isStale = ! $crm->last_sync_at || $crm->last_sync_at->lt(now()->subMinutes($staleMinutes));
            if (! $isStale) {
                return null;
            }

            @set_time_limit(120);

            $summary = [
                'connections' => [],
                'upserted' => 0,
                'removed' => 0,
                'errors' => [],
            ];

            try {
                $result = $this->sync->syncConnection($crm, false, null);
                $summary['connections'][$crm->id] = [
                    'name' => $crm->name,
                    'full' => false,
                    'upserted' => $result['upserted'],
                    'removed' => $result['removed'],
                ];
                $summary['upserted'] = (int) $result['upserted'];
                $summary['removed'] = (int) $result['removed'];
            } catch (Throwable $e) {
                $summary['errors'][] = $crm->name.': '.$e->getMessage();
                Log::warning('desk auto-sync failed', [
                    'crm_id' => $crm->id,
                    'error' => $e->getMessage(),
                ]);
            }

            return $summary;
        } finally {
            $lock->release();
        }
    }
}
