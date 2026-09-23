<?php
$h = file_get_contents(__DIR__.'/storage/app/kp_card_hist.html');
preg_match('/id="customer-requests-grid"([\s\S]{0,120000})/', $h, $g);
$chunk = $g[0] ?? '';
preg_match_all('/<tr[^>]*data-key="(\d+)"[^>]*>/u', $chunk, $keys);
echo "keys=".implode(',', $keys[1])."\n";

// Try finding all tables after customer title
if (preg_match('/История заказ[\s\S]{0,500}/u', $h, $m)) {
    echo "TITLE: ".preg_replace('/\s+/',' ',strip_tags($m[0]))."\n";
}

// count data-key with 6+ digits in whole file near grid
preg_match_all('/data-key="(\d{6,})"/', $chunk, $all);
echo "long keys unique=".count(array_unique($all[1]))." : ".implode(',', array_unique($all[1]))."\n";

// maybe history is loaded via PJAX / separate URL
foreach (['customer-requests', 'related', 'history', 'prev-req'] as $n) {
    if (preg_match('/'.$n.'[^"\']{0,80}/i', $h, $mm)) echo "HIT $n: ".preg_replace('/\s+/',' ',$mm[0])."\n";
}
