<?php
require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

echo "cities in cache:\n";
foreach (App\Models\DeskOrderCache::query()
    ->selectRaw('city_id, city_name, count(*) c')
    ->groupBy('city_id', 'city_name')
    ->orderBy('city_name')
    ->get() as $r) {
    echo "{$r->city_id} | {$r->city_name} | {$r->c}\n";
}
echo "credentials:\n";
foreach (App\Models\Crm2CityCredential::query()->get() as $c) {
    echo "{$c->city_id} name=".($c->city_name ?? '?')." status={$c->status}\n";
}
echo "connections:\n";
foreach (App\Models\CrmConnection::all() as $c) {
    echo "{$c->id} {$c->name} type={$c->type} status={$c->status} last_sync={$c->last_sync_at} err=".substr((string)$c->last_error,0,80)."\n";
}
