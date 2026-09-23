<?php

require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Adapters\Crm2HttpAdapter;
use App\Models\Crm2CityCredential;
use App\Models\CrmConnection;
use ReflectionClass;

$conn = CrmConnection::query()->where('type', 'crm2_http')->firstOrFail();
$cred = Crm2CityCredential::query()->whereNotNull('login')->orderBy('id')->firstOrFail();
$a = new Crm2HttpAdapter($conn, $cred);
$a->authenticate();
$ref = new ReflectionClass($a);
$client = $ref->getMethod('client'); $client->setAccessible(true);
$url = $ref->getMethod('url'); $url->setAccessible(true);
$html = $client->invoke($a)->get($url->invoke($a, '/admin/domain/customer-request/index'))->body();
file_put_contents(__DIR__.'/storage/app/kp_list_live.html', $html);

// sample rows with Отз / late classes
preg_match_all('/<tr[^>]*class="([^"]*)"[^>]*data-key="(\d+)"[^>]*>/i', $html, $rows, PREG_SET_ORDER);
$fb = 0; $late = 0; $samples = [];
foreach ($rows as $r) {
    $cls = $r[1];
    $id = $r[2];
    if (str_contains($cls, 'fb') || str_contains($cls, 'feedback') || str_contains($cls, 'Отз')) {
        $fb++;
    }
    if (str_contains($cls, 'Late') || str_contains($cls, 'late') || str_contains($cls, 'time-warning') || str_contains($cls, 'NeedCall')) {
        $late++;
        if (count($samples) < 5) {
            $samples[] = "LATE {$id} cls={$cls}";
        }
    }
}
echo "rows=".count($rows)." lateish={$late}\n";

// status cells containing Отз
if (preg_match_all('/col__req_status[^>]*>\s*([^<]*Отз[^<]*)</u', $html, $mm)) {
    echo "status Отз samples:\n";
    foreach (array_unique(array_slice($mm[1], 0, 10)) as $s) {
        echo " - ".trim($s)."\n";
    }
}

// row classes mentioning fb/feedback/otz
$clsHits = [];
foreach ($rows as $r) {
    foreach (preg_split('/\s+/', trim($r[1])) as $c) {
        if ($c === '') continue;
        if (preg_match('/fb|feedback|otz|claim|Late|late|NeedCall|warning|OnWay/i', $c)) {
            $clsHits[$c] = ($clsHits[$c] ?? 0) + 1;
        }
    }
}
arsort($clsHits);
echo "interesting classes:\n";
foreach ($clsHits as $c => $n) {
    echo "  {$c}={$n}\n";
}

// time-warning in list
echo 'time-warning count='.substr_count($html, 'time-warning')."\n";
if (preg_match('/time-warning.{0,200}/s', $html, $tm)) {
    echo "tw: ".preg_replace('/\s+/', ' ', $tm[0])."\n";
}

// fetch card with history
$cardId = '2559348';
$card = $client->invoke($a)->get($url->invoke($a, '/admin/domain/customer-request/update?id='.$cardId))->body();
file_put_contents(__DIR__.'/storage/app/kp_card_hist.html', $card);
echo 'card='.strlen($card)." has history=".(str_contains($card, 'История заказов клиента') ? 'Y' : 'n')."\n";
echo 'has feedback modal='.(str_contains($card, 'crFeedbackModal') || str_contains($card, 'отзыв') ? 'Y' : 'n')."\n";

// parse history table rows
if (preg_match('/История заказов клиента[\s\S]{0,8000}/u', $card, $hm)) {
    if (preg_match_all('/<tr[^>]*data-key="(\d+)"[^>]*>([\s\S]*?)<\/tr>/i', $hm[0], $trs, PREG_SET_ORDER)) {
        echo 'history rows='.count($trs)."\n";
        foreach (array_slice($trs, 0, 3) as $tr) {
            $cells = [];
            if (preg_match_all('/<td[^>]*>([\s\S]*?)<\/td>/i', $tr[2], $tds)) {
                foreach ($tds[1] as $td) {
                    $cells[] = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($td))));
                }
            }
            echo ' #'.$tr[1].' => '.implode(' | ', array_slice($cells, 0, 8))."\n";
        }
    }
}

// feedback flags on card
foreach (['is_fb', 'fb_req', 'feedback', '__tIsFb', 'Отз', 'cr-feedback', 'has_feedback'] as $n) {
    if (stripos($card, $n) !== false) {
        echo "card hit: {$n}\n";
    }
}
if (preg_match('/.{0,80}(is_fb|fb_req|feedback_req|__tIsFb).{0,120}/i', $card, $fm)) {
    echo 'fb ctx: '.preg_replace('/\s+/', ' ', $fm[0])."\n";
}
