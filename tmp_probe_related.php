<?php
require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$conn = App\Models\CrmConnection::query()->where('type', 'crm2_http')->first();
$cred = App\Models\Crm2CityCredential::query()->first();
$adapter = app(App\Services\DeskSyncService::class)->makeAdapter($conn, null, $cred);

$ref = new ReflectionClass($adapter);
$auth = $ref->getMethod('authenticate'); $auth->setAccessible(true); $auth->invoke($adapter);
$client = $ref->getMethod('client'); $client->setAccessible(true);
$url = $ref->getMethod('url'); $url->setAccessible(true);
$c = $client->invoke($adapter);

// Find a warranty/repeat order from cache
$sample = App\Models\DeskOrderCache::query()
    ->whereIn('order_type', ['warranty', 'repeat'])
    ->orderByDesc('id')
    ->first();
$id = $sample?->external_id ?: '2572077';
echo "sample=$id type=".($sample?->order_type)."\n";

$res = $c->get($url->invoke($adapter, '/admin/domain/customer-request/update?id='.urlencode($id)));
$html = $res->body();
file_put_contents(__DIR__.'/storage/app/kp_card_rel.html', $html);
echo "len=".strlen($html)."\n";

// RelatedData / history URLs
if (preg_match_all('/RelatedData[\s\S]{0,800}/', $html, $mm)) {
    foreach ($mm[0] as $i => $chunk) {
        echo "=== RelatedData $i ===\n".preg_replace('/\s+/', ' ', $chunk)."\n";
    }
}
if (preg_match_all('/customer-requests[^"\']{0,120}/i', $html, $mm2)) {
    echo "customer-requests hits:\n";
    foreach (array_unique($mm2[0]) as $x) echo "  $x\n";
}
if (preg_match('/История заказ[\s\S]{0,300}/u', $html, $m)) {
    echo "TITLE: ".preg_replace('/\s+/', ' ', strip_tags($m[0]))."\n";
}
// grid row count
libxml_use_internal_errors(true);
$dom = new DOMDocument();
@$dom->loadHTML('<?xml encoding="UTF-8">'.$html);
$xp = new DOMXPath($dom);
$trs = $xp->query('//*[@id="customer-requests-grid"]//tbody/tr');
echo "grid tr=".($trs?$trs->length:0)."\n";
if ($trs) {
    foreach ($trs as $i => $tr) {
        if ($i>5) break;
        echo "  key=".$tr->getAttribute('data-key')." ".substr(preg_replace('/\s+/',' ',$tr->textContent),0,100)."\n";
    }
}

// look for ajax urls with person/customer id
foreach (['person_id','customer_id','client_id','phone','get-related','related-data','customer-request/index'] as $n) {
    if (preg_match('/'.preg_quote($n,'/').'[^"\']{0,100}/i', $html, $m)) {
        echo "HIT $n: ".preg_replace('/\s+/',' ',$m[0])."\n";
    }
}
