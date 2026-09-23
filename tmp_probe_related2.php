<?php
require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$conn = App\Models\CrmConnection::query()->where('type', 'crm2_http')->first();
$sample = App\Models\DeskOrderCache::query()
    ->whereIn('order_type', ['warranty', 'repeat'])
    ->whereNotNull('city_id')
    ->orderByDesc('id')
    ->first();
$id = $sample->external_id;
$cityId = $sample->city_id;
$cred = App\Models\Crm2CityCredential::query()->where('city_id', $cityId)->first();
echo "sample=$id type={$sample->order_type} city=$cityId cred=".($cred?->id??'none')."\n";

$adapter = app(App\Services\DeskSyncService::class)->makeAdapter($conn, null, $cred);
$ref = new ReflectionClass($adapter);
$auth = $ref->getMethod('authenticate'); $auth->setAccessible(true); $auth->invoke($adapter);
$client = $ref->getMethod('client'); $client->setAccessible(true);
$url = $ref->getMethod('url'); $url->setAccessible(true);
$c = $client->invoke($adapter);

$res = $c->get($url->invoke($adapter, '/admin/domain/customer-request/update?id='.urlencode($id)));
$html = $res->body();
file_put_contents(__DIR__.'/storage/app/kp_card_rel.html', $html);
echo "status=".$res->status()." len=".strlen($html)."\n";
echo "login=". (str_contains($html,'login-form')?'yes':'no')."\n";
if (preg_match('/<h1[^>]*>([\s\S]*?)<\/h1>/iu', $html, $hm)) {
    echo 'H1: '.trim(preg_replace('/\s+/', ' ', strip_tags($hm[1])))."\n";
}
if (preg_match('/История заказ[\s\S]{0,400}/u', $html, $m)) {
    echo "TITLE: ".preg_replace('/\s+/', ' ', strip_tags($m[0]))."\n";
}

libxml_use_internal_errors(true);
$dom = new DOMDocument();
@$dom->loadHTML('<?xml encoding="UTF-8">'.$html);
$xp = new DOMXPath($dom);
$trs = $xp->query('//*[@id="customer-requests-grid"]//tbody/tr');
echo "grid tr=".($trs?$trs->length:0)."\n";
if ($trs) {
    foreach ($trs as $i => $tr) {
        if ($i>8) break;
        $key = $tr->getAttribute('data-key');
        $tds = $xp->query('./td', $tr);
        echo "  key=$key tds=".($tds?$tds->length:0)." ".substr(preg_replace('/\s+/',' ',$tr->textContent),0,120)."\n";
    }
}

// RelatedData JS
if (preg_match('/RelatedData\s*=\s*function[\s\S]{0,2000}/', $html, $mm)) {
    echo "=== RelatedData fn ===\n".preg_replace('/\s+/', ' ', $mm[0])."\n";
}
// URLs containing related / history / person
if (preg_match_all('/[\'\"](\/admin\/[^\'\"]*(?:related|history|customer-request\/index|person)[^\'\"]*)[\'\"]/i', $html, $um)) {
    echo "URLs:\n";
    foreach (array_unique($um[1]) as $u) echo "  $u\n";
}

// parse via adapter
$parse = $ref->getMethod('parseOrderDetailHtml');
$parse->setAccessible(true);
$d = $parse->invoke($adapter, $html, $id);
echo 'parsed_hist='.count($d['client_history']??[])."\n";
echo json_encode($d['client_history']??[], JSON_UNESCAPED_UNICODE)."\n";
