<?php

namespace App\Services;

use App\Adapters\Crm2HttpAdapter;
use App\Models\Crm2CityCredential;
use App\Models\CrmConnection;
use App\Support\DeskCardMasters;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

class KpMasterSyncService
{
    /**
     * Забрать мастеров из КП по учётке филиала и upsert в CRM1.
     *
     * @return array{fetched:int,created:int,updated:int,linked:int,skipped:int,deactivated:int,errors:list<string>}
     */
    public function syncCity(Crm2CityCredential $cred, ?int $actingUserId = null): array
    {
        if (! $cred->hasCredentials()) {
            throw new RuntimeException('Нет логина/пароля КП для филиала');
        }

        $crm2 = CrmConnection::query()->where('type', 'crm2_http')->first();
        if (! $crm2) {
            throw new RuntimeException('Подключение kp-lead-centre не настроено');
        }

        $adapter = new Crm2HttpAdapter($crm2, $cred);
        $masters = $adapter->fetchMasters();

        $payloadMasters = array_map(function (array $m) {
            return [
                'kp_employee_id' => $m['kp_employee_id'],
                'name' => $m['name'],
                'email' => $m['email'] ?? null,
                'passport' => $m['passport'] ?? null,
                'status' => $m['status'] ?? null,
                'is_active' => (bool) ($m['is_active'] ?? true),
                'city_names' => array_values(array_filter([(string) ($m['city_name'] ?? '')])),
            ];
        }, $masters);

        if ($payloadMasters === []) {
            return [
                'fetched' => 0,
                'created' => 0,
                'updated' => 0,
                'linked' => 0,
                'skipped' => 0,
                'deactivated' => 0,
                'errors' => [],
            ];
        }

        DeskCardMasters::remember(
            (int) $crm2->id,
            (int) $cred->city_id,
            DeskCardMasters::fromKpRows($payloadMasters),
            true
        );

        $base = rtrim((string) config('desk.crm1_base_url'), '/');
        $token = (string) config('desk.crm1_token');
        if ($base === '' || $token === '') {
            throw new RuntimeException('CRM1 API не настроен (CRM1_BASE_URL / DESK_API_TOKEN)');
        }

        try {
            $res = Http::baseUrl($base)
                ->timeout(60)
                ->acceptJson()
                ->asJson()
                ->withToken($token)
                ->withHeaders([
                    'X-Desk-User-Id' => (string) ($actingUserId ?? ''),
                ])
                ->post('/api/v1/desk/masters/upsert', [
                    'city_id' => (int) $cred->city_id,
                    'masters' => $payloadMasters,
                    'deactivate_missing' => true,
                ]);
        } catch (Throwable $e) {
            throw new RuntimeException('CRM API: '.$e->getMessage(), 0, $e);
        }

        if (! $res->successful()) {
            throw new RuntimeException('CRM API HTTP '.$res->status().': '.mb_substr($res->body(), 0, 300));
        }

        $json = $res->json() ?? [];

        return [
            'fetched' => count($payloadMasters),
            'created' => (int) ($json['created'] ?? 0),
            'updated' => (int) ($json['updated'] ?? 0),
            'linked' => (int) ($json['linked'] ?? 0),
            'skipped' => (int) ($json['skipped'] ?? 0),
            'deactivated' => (int) ($json['deactivated'] ?? 0),
            'errors' => array_values((array) ($json['errors'] ?? [])),
        ];
    }
}
