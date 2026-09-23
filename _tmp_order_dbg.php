<?php

require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$o = App\Models\DeskOrderCache::query()->where('external_id', '2571884')->first();
if (! $o) {
    echo "NOT FOUND\n";
    exit;
}
echo json_encode([
    'call_at_local' => (string) $o->call_at_local,
    'timezone' => $o->timezone,
    'master_name' => $o->master_name,
    'master_external_id' => $o->master_external_id,
    'address' => $o->address,
    'street' => App\Support\DeskAddressOffice::streetAddressForDisplay($o->address, $o->address_office),
    'app_tz' => config('app.timezone'),
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), PHP_EOL;

// live fetch
$conn = $o->connection;
$cred = App\Models\Crm2CityCredential::query()->where('city_id', $o->city_id)->first();
$adapter = app(App\Services\DeskSyncService::class)->makeAdapter($conn, null, $cred);
$p = $adapter->fetchOrder('2571884');
echo "LIVE:\n";
echo json_encode([
    'call_at_local' => $p['call_at_local'] ?? null,
    'opened_at_raw' => $p['opened_at_raw'] ?? null,
    'master_name' => $p['master_name'] ?? null,
    'master_id' => $p['master_id'] ?? null,
    'address' => $p['address'] ?? null,
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), PHP_EOL;
