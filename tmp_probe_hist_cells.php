<?php
$h = file_get_contents(__DIR__.'/storage/app/kp_card_hist.html');
if (!preg_match('/id="customer-requests-grid"([\s\S]{0,20000})/', $h, $g)) {
    echo "no grid\n";
    exit;
}
$chunk = $g[0];
if (!preg_match_all('/<tr[^>]*data-key="(\d+)"([\s\S]*?)<\/tr>/u', $chunk, $rows, PREG_SET_ORDER)) {
    echo "no rows\n";
    exit;
}
echo 'rows='.count($rows)."\n";
foreach (array_slice($rows, 0, 3) as $r) {
    if (preg_match_all('/<td[^>]*>([\s\S]*?)<\/td>/u', $r[0], $tds)) {
        echo "id={$r[1]} cells=".count($tds[1])."\n";
        foreach ($tds[1] as $i => $td) {
            echo "  $i: ".trim(preg_replace('/\s+/', ' ', strip_tags($td)))."\n";
        }
    }
}

// feedback fields on card
foreach (['__tIsFb', '__tIsFbReq', 'is_fb_req', 'cr_feedback', 'feedback', 'only_is_fb'] as $n) {
    if (stripos($h, $n) !== false) {
        echo "HIT $n\n";
        if (preg_match('/'.preg_quote($n, '/').'[^\\n]{0,120}/i', $h, $m)) {
            echo '  '.preg_replace('/\s+/', ' ', $m[0])."\n";
        }
    }
}
// h1 status with otz?
if (preg_match('/<h1[^>]*>([\s\S]*?)<\/h1>/iu', $h, $hm)) {
    echo 'H1: '.trim(preg_replace('/\s+/', ' ', strip_tags($hm[1])))."\n";
}
