<?php
require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$conn = App\Models\CrmConnection::query()->where('type', 'crm2_http')->first();
$adapter = app(App\Services\DeskSyncService::class)->makeAdapter($conn, null, App\Models\Crm2CityCredential::query()->first());

$ref = new ReflectionClass($adapter);

$html = file_get_contents(__DIR__.'/storage/app/kp_list_live.html');
$parseDetail = $ref->getMethod('parseOrderDetailHtml');
$parseDetail->setAccessible(true);
$card = file_get_contents(__DIR__.'/storage/app/kp_card_hist.html');
$d = $parseDetail->invoke($adapter, $card, '2559348');
echo 'hist_count='.count($d['client_history']??[])."\n";
if (!empty($d['client_history'][0])) {
    echo 'first='.json_encode($d['client_history'][0], JSON_UNESCAPED_UNICODE)."\n";
}
echo 'needs_fb_card='.var_export($d['needs_feedback'], true)."\n";

$st = $ref->getMethod('statusTextNeedsFeedback');
$st->setAccessible(true);
echo 'otz1='.var_export($st->invoke($adapter, 'Ожидает (Отз)'), true)."\n";
echo 'otz2='.var_export($st->invoke($adapter, 'Ожидает'), true)."\n";

$lm = $ref->getMethod('parseOrdersHtml');
$lm->setAccessible(true);
$orders = $lm->invoke($adapter, $html);
$fb = array_values(array_filter($orders, fn($o)=>!empty($o['needs_feedback'])));
echo 'list_total='.count($orders).' with_fb='.count($fb)."\n";
if ($fb) echo 'sample_fb='.$fb[0]['external_id'].' '.$fb[0]['raw_status']."\n";

