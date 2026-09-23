<?php
require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$conn = App\Models\CrmConnection::query()->where('type', 'crm2_http')->first();
$cred = App\Models\Crm2CityCredential::query()->first();
$sync = app(App\Services\DeskSyncService::class);

// Find sync method signature
$ref = new ReflectionMethod($sync, 'syncConnection');
echo 'sync params: ';
foreach ($ref->getParameters() as $p) {
    echo $p->getName().' ';
}
echo PHP_EOL;

// Try common call patterns
try {
    $r = $sync->syncConnection($conn, true);
    echo 'result1='.json_encode($r).PHP_EOL;
} catch (Throwable $e) {
    echo 'err1='.$e->getMessage().PHP_EOL;
    try {
        $r = $sync->sync($conn);
        echo 'result2='.json_encode($r).PHP_EOL;
    } catch (Throwable $e2) {
        echo 'err2='.$e2->getMessage().PHP_EOL;
    }
}

echo 'fb='.App\Models\DeskOrderCache::query()->where('needs_feedback', true)->count().PHP_EOL;
$s = App\Models\DeskOrderCache::query()->where('needs_feedback', true)->first();
echo 'sample='.($s?->external_id ?? 'none').' status='.($s?->raw_status ?? '').PHP_EOL;
