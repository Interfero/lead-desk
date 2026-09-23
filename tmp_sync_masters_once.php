<?php
require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Crm2CityCredential;
use App\Services\KpMasterSyncService;

$cred = Crm2CityCredential::query()->whereNotNull('login')->whereNotNull('password')->orderBy('id')->first();
echo "city_id={$cred->city_id} name={$cred->city_name}\n";
$svc = app(KpMasterSyncService::class);
$r = $svc->syncCity($cred, 0);
echo json_encode($r, JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT),"\n";
