<?php

namespace App\Http\Controllers;

use App\Models\Crm2CityCredential;
use App\Models\CrmConnection;
use App\Models\DeskHistorySyncRun;
use App\Services\DeskHistorySyncService;
use App\Services\DeskSyncService;
use App\Support\DeskAccess;
use App\Support\KpHttpError;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Throwable;

class DeskSettingsController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->session()->get('desk_user');
        abort_unless(DeskAccess::canManageCrm2Settings($user), 403);

        $cities = $this->citiesForUser($user);
        $creds = Crm2CityCredential::query()
            ->whereIn('city_id', collect($cities)->pluck('id')->all() ?: [-1])
            ->get()
            ->keyBy('city_id');

        $crm2 = CrmConnection::query()->where('type', 'crm2_http')->first();
        $connections = CrmConnection::query()->orderBy('id')->get();
        $crm2Configured = Crm2CityCredential::query()
            ->whereNotNull('login')
            ->whereNotNull('password')
            ->exists();
        $historyRuns = DeskHistorySyncRun::query()
            ->whereIn('city_id', collect($cities)->pluck('id')->all() ?: [-1])
            ->latest('created_at')
            ->get()
            ->unique('city_id')
            ->keyBy('city_id');

        return view('desk.settings', compact('user', 'cities', 'creds', 'crm2', 'connections', 'crm2Configured', 'historyRuns'));
    }

    public function save(Request $request)
    {
        $user = $request->session()->get('desk_user');
        abort_unless(DeskAccess::canManageCrm2Settings($user), 403);

        $validated = $request->validate([
            'city_id' => 'required|integer',
            'city_name' => 'nullable|string|max:255',
            'login' => 'nullable|string|max:255',
            'password' => 'nullable|string|max:255',
            'clear_password' => 'nullable|boolean',
        ]);

        $cityId = (int) $validated['city_id'];
        abort_unless(DeskAccess::canAccessCity($user, $cityId), 403);

        $cred = Crm2CityCredential::query()->firstOrNew(['city_id' => $cityId]);
        $cred->city_name = $validated['city_name'] ?: $cred->city_name;
        if (array_key_exists('login', $validated)) {
            $cred->login = $validated['login'] ?: null;
        }
        if (! empty($validated['clear_password'])) {
            $cred->password = null;
        } elseif (filled($validated['password'] ?? null)) {
            $cred->password = $validated['password'];
        }
        $cred->updated_by = (int) ($user['id'] ?? 0) ?: null;
        if (! $cred->hasCredentials()) {
            $cred->status = 'inactive';
            $cred->last_error = null;
        } elseif ($cred->status === 'inactive') {
            $cred->status = 'active';
        }
        $cred->save();

        // Активируем глобальное подключение CRM2, если есть хотя бы один доступ
        $crm2 = CrmConnection::query()->where('type', 'crm2_http')->first();
        if ($crm2 && Crm2CityCredential::query()->whereNotNull('login')->whereNotNull('password')->exists()) {
            if ($crm2->status === 'inactive') {
                $crm2->status = 'active';
                $crm2->name = 'kp-lead-centre';
                $crm2->base_url = $crm2->base_url ?: config('desk.crm2_base_url');
                $crm2->save();
            }
        }

        return redirect()->route('desk.settings')->with('success', 'Доступ kp для филиала сохранён');
    }

    public function test(Request $request, DeskSyncService $sync)
    {
        $user = $request->session()->get('desk_user');
        abort_unless(DeskAccess::canManageCrm2Settings($user), 403);

        $cityId = (int) $request->validate(['city_id' => 'required|integer'])['city_id'];
        abort_unless(DeskAccess::canAccessCity($user, $cityId), 403);

        $crm2 = CrmConnection::query()->where('type', 'crm2_http')->firstOrFail();
        $cred = Crm2CityCredential::query()->where('city_id', $cityId)->first();
        if (! $cred?->hasCredentials()) {
            return redirect()->route('desk.settings')->with('error', 'Сначала сохраните логин и пароль');
        }

        try {
            $adapter = $sync->makeAdapter($crm2, (int) $user['id'], $cred);
            $adapter->authenticate();
            $cred->forceFill(['status' => 'active', 'last_error' => null])->save();

            return redirect()->route('desk.settings')->with('success', 'Вход в kp-lead-centre успешен');
        } catch (Throwable $e) {
            $cred->markError($e->getMessage());

            return redirect()->route('desk.settings')->with('error', KpHttpError::message($e));
        }
    }

    public function syncCity(Request $request, int $cityId, DeskSyncService $sync)
    {
        $user = $request->session()->get('desk_user');
        abort_unless(DeskAccess::canManageCrm2Settings($user), 403);
        abort_unless(DeskAccess::canAccessCity($user, $cityId), 403);

        $crm2 = CrmConnection::query()->where('type', 'crm2_http')->firstOrFail();

        try {
            $r = $sync->syncCrm2($crm2, true, (int) $user['id'], $cityId);

            return redirect()->route('desk.settings')
                ->with('success', "Синхронизация филиала: +{$r['upserted']} / −{$r['removed']}");
        } catch (Throwable $e) {
            return redirect()->route('desk.settings')->with('error', KpHttpError::message($e));
        }
    }

    public function queueHistory(Request $request, int $cityId, int $days, DeskHistorySyncService $history)
    {
        $user = $request->session()->get('desk_user');
        abort_unless(DeskAccess::canManageCrm2Settings($user), 403);
        abort_unless(DeskAccess::canAccessCity($user, $cityId), 403);

        try {
            $history->startManualRun($cityId, $days, (int) ($user['id'] ?? 0));

            $periodLabel = match ($days) {
                365 => '1 год',
                730 => '2 года',
                default => "{$days} дн.",
            };

            return redirect()->route('desk.settings')
                ->with('success', "История KP за {$periodLabel} поставлена в очередь.");
        } catch (Throwable $e) {
            return redirect()->route('desk.settings')->with('error', KpHttpError::message($e));
        }
    }

    public function syncMasters(Request $request, int $cityId, \App\Services\KpMasterSyncService $masters)
    {
        $user = $request->session()->get('desk_user');
        abort_unless(DeskAccess::canManageCrm2Settings($user), 403);
        abort_unless(DeskAccess::canAccessCity($user, $cityId), 403);

        $cred = Crm2CityCredential::query()->where('city_id', $cityId)->first();
        if (! $cred?->hasCredentials()) {
            return redirect()->route('desk.settings')->with('error', 'Сначала сохраните логин и пароль');
        }

        try {
            $r = $masters->syncCity($cred, (int) ($user['id'] ?? 0));
            $msg = "Мастера КП→CRM: найдено {$r['fetched']}, новых {$r['created']}, обновлено {$r['updated']}, связано {$r['linked']}";
            if (($r['deactivated'] ?? 0) > 0) {
                $msg .= ", снято с филиала/уволено {$r['deactivated']}";
            }
            if ($r['skipped'] > 0) {
                $msg .= ", пропущено {$r['skipped']}";
            }
            if ($r['errors'] !== []) {
                $msg .= '. Ошибки: '.implode('; ', array_slice($r['errors'], 0, 3));
            }

            return redirect()->route('desk.settings')->with('success', $msg);
        } catch (Throwable $e) {
            return redirect()->route('desk.settings')->with('error', KpHttpError::message($e));
        }
    }

    /**
     * @param  array<string, mixed>  $user
     * @return list<array{id:int,name:string}>
     */
    protected function citiesForUser(array $user): array
    {
        try {
            $res = Http::baseUrl(rtrim((string) config('desk.crm1_base_url'), '/'))
                ->timeout(10)
                ->withToken((string) config('desk.crm1_token'))
                ->withHeaders(['X-Desk-User-Id' => (string) ($user['id'] ?? '')])
                ->acceptJson()
                ->get('/api/v1/desk/cities');

            if ($res->successful()) {
                $cities = collect($res->json('cities') ?? [])
                    ->map(fn ($c) => ['id' => (int) $c['id'], 'name' => (string) ($c['name'] ?? ('#'.$c['id']))])
                    ->values()
                    ->all();
                $allowed = DeskAccess::cityIds($user);
                if ($allowed !== null) {
                    $cities = array_values(array_filter($cities, fn ($c) => in_array($c['id'], $allowed, true)));
                }

                return $cities;
            }
        } catch (Throwable) {
        }

        // fallback: города из кэша заказов
        $q = \App\Models\DeskOrderCache::query()
            ->whereNotNull('city_id')
            ->selectRaw('city_id as id, MAX(city_name) as name')
            ->groupBy('city_id')
            ->orderBy('name');
        $allowed = DeskAccess::cityIds($user);
        if ($allowed !== null) {
            $q->whereIn('city_id', $allowed);
        }

        return $q->get()->map(fn ($r) => ['id' => (int) $r->id, 'name' => $r->name ?: ('#'.$r->id)])->all();
    }
}
