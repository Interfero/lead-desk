<?php
require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Crm2CityCredential;
use App\Models\CrmConnection;
use App\Adapters\Crm2HttpAdapter;

$cred = Crm2CityCredential::query()->whereNotNull('login')->whereNotNull('password')->orderBy('id')->first();
$crm2 = CrmConnection::query()->where('type', 'crm2_http')->firstOrFail();
$a = new Crm2HttpAdapter($crm2, $cred);
$a->authenticate();
$ref = new ReflectionClass($a);
$client = $ref->getMethod('client'); $client->setAccessible(true);
$url = $ref->getMethod('url'); $url->setAccessible(true);
$http = $client->invoke($a);

$query = http_build_query([
    'UserSearch[role_type]' => 'employee',
    'UserSearch[status]' => '10', // guess active? try empty later
]);
// first discover status values
$res = $http->get($url->invoke($a, '/admin/user/index?'.http_build_query([
    'UserSearch[role_type]' => 'employee',
])));
file_put_contents('/tmp/kp_masters.html', $res->body());
$html = $res->body();
echo "len=".strlen($html)."\n";
if (preg_match('/summary\">Показаны записи <b>(\d+)-(\d+)<\/b> из <b>(\d+)/u', $html, $m)) {
    echo "range={$m[1]}-{$m[2]} total={$m[3]}\n";
}
if (preg_match('/name="UserSearch\[status\]"[^>]*>(.*?)<\/select>/is', $html, $sm)) {
    preg_match_all('/<option[^>]*value="([^"]*)"[^>]*>([^<]*)/i', $sm[1], $opts, PREG_SET_ORDER);
    foreach ($opts as $o) echo "status {$o[1]} => ".trim(html_entity_decode($o[2]))."\n";
}
if (preg_match('/name="UserSearch\[city_id\]"[^>]*>(.*?)<\/select>/is', $html, $cm)) {
    preg_match_all('/<option[^>]*value="([^"]*)"[^>]*>([^<]*)/i', $cm[1], $opts, PREG_SET_ORDER);
    echo "cities=".count($opts)."\n";
    foreach (array_slice($opts, 0, 8) as $o) echo "city {$o[1]} => ".trim(html_entity_decode(strip_tags($o[2])))."\n";
}
// count masters on page
$prev = libxml_use_internal_errors(true);
$dom = new DOMDocument();
$dom->loadHTML('<?xml encoding="UTF-8">'.$html);
libxml_clear_errors(); libxml_use_internal_errors($prev);
$xp = new DOMXPath($dom);
$n = 0;
foreach ($xp->query('//table//tr') as $i => $tr) {
    if ($i===0) continue;
    $tds = $xp->query('./td', $tr);
    if (!$tds || $tds->length < 9) continue;
    $id = trim($tds->item(0)->textContent);
    $role = trim($tds->item(8)->textContent);
    if (preg_match('/^\d+$/', $id) && mb_stripos($role, 'Мастер') !== false) $n++;
}
echo "masters_on_page=$n\n";
