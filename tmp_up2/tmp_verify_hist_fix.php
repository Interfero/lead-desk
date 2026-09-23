<?php
require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$conn = App\Models\CrmConnection::query()->where('type', 'crm2_http')->first();
$adapter = app(App\Services\DeskSyncService::class)->makeAdapter($conn, null, App\Models\Crm2CityCredential::query()->first());
$html = file_get_contents(__DIR__.'/storage/app/kp_card_otz.html');
$d = $adapter->parseOrderDetailHtml($html, '2572077');
echo 'hist='.count($d['client_history'] ?? []).PHP_EOL;
foreach ($d['client_history'] ?? [] as $h) {
    echo $h['external_id'].' '.$h['order_type'].' '.$h['status'].($h['is_current']?' *':'').PHP_EOL;
}
