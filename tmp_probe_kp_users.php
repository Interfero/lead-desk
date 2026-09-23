<?php
// Probe KP /admin/user/index structure using stored city credentials.
require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Crm2CityCredential;
use App\Models\CrmConnection;
use App\Adapters\Crm2HttpAdapter;

$cred = Crm2CityCredential::query()
    ->whereNotNull('login')
    ->whereNotNull('password')
    ->orderBy('id')
    ->first();

if (!$cred) {
    fwrite(STDERR, "no credentials\n");
    exit(1);
}

$crm2 = CrmConnection::query()->where('type', 'crm2_http')->firstOrFail();
$adapter = new Crm2HttpAdapter($crm2, $cred);
$adapter->authenticate();

$ref = new ReflectionClass($adapter);
$client = $ref->getMethod('client');
$client->setAccessible(true);
$url = $ref->getMethod('url');
$url->setAccessible(true);

$http = $client->invoke($adapter);
$res = $http->get($url->invoke($adapter, '/admin/user/index'));
file_put_contents('/tmp/kp_user_index.html', $res->body());
echo "status=".$res->status()." len=".strlen($res->body())." city=".$cred->city_name."\n";

// Extract table headers and first few rows roughly
$html = $res->body();
if (preg_match_all('/<th[^>]*>(.*?)<\/th>/is', $html, $ths)) {
    $headers = array_map(fn($h) => trim(html_entity_decode(strip_tags($h), ENT_QUOTES|ENT_HTML5, 'UTF-8')), $ths[1]);
    echo "headers=".json_encode($headers, JSON_UNESCAPED_UNICODE)."\n";
}
if (preg_match('/href="([^"]*admin\/user[^"]*)"/i', $html, $m)) {
    echo "sample_user_link=".$m[1]."\n";
}
// role filters / pills
if (preg_match_all('/мастер|master|роль|role|employee/iu', $html, $mm)) {
    echo "keyword_hits=".count($mm[0])."\n";
}
echo "title_snip=".substr(preg_replace('/\s+/', ' ', strip_tags($html)), 0, 200)."\n";
