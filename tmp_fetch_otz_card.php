<?php
require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$conn = App\Models\CrmConnection::query()->where('type', 'crm2_http')->first();
$cred = App\Models\Crm2CityCredential::query()->first();
$adapter = app(App\Services\DeskSyncService::class)->makeAdapter($conn, null, $cred);

// use reflection to get HTML
$ref = new ReflectionClass($adapter);
$m = $ref->getMethod('authenticate');
$m->setAccessible(true);
$m->invoke($adapter);
$client = $ref->getMethod('client');
$client->setAccessible(true);
$url = $ref->getMethod('url');
$url->setAccessible(true);
$c = $client->invoke($adapter);
$path = '/admin/domain/customer-request/update?id=2572077';
$res = $c->get($url->invoke($adapter, $path));
$html = $res->body();
file_put_contents(__DIR__.'/storage/app/kp_card_otz.html', $html);
echo "status=". $res->status()." len=".strlen($html)."\n";
if (preg_match('/<h1[^>]*>([\s\S]*?)<\/h1>/iu', $html, $hm)) {
    echo 'H1: '.trim(preg_replace('/\s+/', ' ', strip_tags($hm[1])))."\n";
}
$needle = "Отз";
$p = mb_strpos($html, $needle);
echo "otz_pos=".($p===false?'none':$p)."\n";
if ($p !== false) {
    echo substr($html, max(0, $p-100), 200)."\n";
}
foreach (['is_fb','fb_req','need_fb','отзыв','Отз'] as $n) {
    if (stripos($html, $n) !== false) echo "HIT $n\n";
}
