<?php
require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$h = file_get_contents(__DIR__.'/storage/app/kp_list_live.html');
if ($h === false) {
    echo "no list html\n";
    exit(1);
}

// find status cells containing Отз
$needle = 'Отз';
$pos = 0;
$n = 0;
while (($p = mb_strpos($h, $needle, $pos)) !== false && $n < 3) {
    $start = max(0, $p - 200);
    $chunk = substr($h, $start, 500);
    echo "=== OTZ chunk $n ===\n";
    echo preg_replace('/\s+/', ' ', strip_tags($chunk))."\n\n";
    // class nearby
    if (preg_match('/class="([^"]*)"[^>]*>[^<]*Отз/u', substr($h, max(0, $p - 400), 500), $m)) {
        echo "class near: {$m[1]}\n";
    }
    $pos = $p + 2;
    $n++;
}

// row 2572077
if (preg_match('/data-key="2572077"([\s\S]{0,2500})/', $h, $m)) {
    echo "=== row 2572077 text ===\n";
    echo preg_replace('/\s+/', ' ', strip_tags($m[0]))."\n";
    if (preg_match_all('/col__[a-z_]+/', $m[0], $cols)) {
        echo "cols: ".implode(',', array_unique($cols[0]))."\n";
    }
    if (preg_match('/col__req_status[\s\S]{0,300}/', $m[0], $st)) {
        echo "status cell html: ".preg_replace('/\s+/', ' ', $st[0])."\n";
    }
}

$card = @file_get_contents(__DIR__.'/storage/app/kp_card_hist.html');
if ($card) {
    echo "\n=== history grid ===\n";
    if (preg_match('/id="customer-requests-grid"([\s\S]{0,8000})/', $card, $g)) {
        echo "grid found len=".strlen($g[0])."\n";
        // table headers
        if (preg_match_all('/<th[^>]*>([\s\S]*?)<\/th>/u', $g[0], $ths)) {
            foreach ($ths[1] as $th) {
                echo "TH: ".trim(preg_replace('/\s+/', ' ', strip_tags($th)))."\n";
            }
        }
        // first data rows
        if (preg_match_all('/<tr[^>]*data-key="(\d+)"([\s\S]*?)<\/tr>/u', $g[0], $rows, PREG_SET_ORDER)) {
            echo "history rows=".count($rows)."\n";
            foreach (array_slice($rows, 0, 3) as $r) {
                echo "hist#{$r[1]}: ".preg_replace('/\s+/', ' ', strip_tags($r[0]))."\n";
            }
        } else {
            // alternate row pattern
            if (preg_match_all('/<tr[^>]*>([\s\S]*?)<\/tr>/u', $g[0], $rows2)) {
                echo "plain tr=".count($rows2[0])."\n";
                foreach (array_slice($rows2[0], 0, 4) as $r) {
                    $t = trim(preg_replace('/\s+/', ' ', strip_tags($r)));
                    if ($t !== '') echo "TR: $t\n";
                }
            }
        }
    } else {
        echo "no customer-requests-grid\n";
        if (preg_match('/истори[яи] заказ/iu', $card)) echo "has history text\n";
    }
}
