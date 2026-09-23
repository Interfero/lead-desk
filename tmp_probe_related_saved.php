<?php
$h = file_get_contents(__DIR__.'/storage/app/kp_card_otz.html');
echo "len=".strlen($h)."\n";
if (preg_match('/RelatedData\s*=\s*function[\s\S]{0,3000}/', $h, $m)) {
    echo "=== RelatedData ===\n".preg_replace('/\s+/', ' ', $m[0])."\n\n";
}
if (preg_match('/История заказ[\s\S]{0,500}/u', $h, $m)) {
    echo "TITLE: ".preg_replace('/\s+/', ' ', strip_tags($m[0]))."\n";
}
if (preg_match_all('/[\'\"](\/admin\/[^\'\"]{5,200})[\'\"]/', $h, $um)) {
    $u = array_values(array_filter(array_unique($um[1]), function ($x) {
        return preg_match('/related|history|customer-request|person|phone|grid/i', $x);
    }));
    echo "interesting URLs:\n";
    foreach (array_slice($u, 0, 40) as $x) echo "  $x\n";
}
// look for pjax reload of grid
if (preg_match_all('/customer-requests-grid[\s\S]{0,200}/', $h, $mm)) {
    echo "grid mentions=".count($mm[0])."\n";
}
libxml_use_internal_errors(true);
$dom = new DOMDocument();
@$dom->loadHTML('<?xml encoding="UTF-8">'.$h);
$xp = new DOMXPath($dom);
$trs = $xp->query('//*[@id="customer-requests-grid"]//tbody/tr');
echo "otz grid tr=".($trs?$trs->length:0)."\n";
if ($trs) {
    foreach ($trs as $i => $tr) {
        if ($i > 10) break;
        echo "  key=".$tr->getAttribute('data-key')." tds=".$xp->query('./td',$tr)->length." ".substr(preg_replace('/\s+/',' ',$tr->textContent),0,100)."\n";
    }
}

// guarantee field - previous order link
if (preg_match('/__tIsGuarantee[^>]*>/', $h, $m)) echo $m[0]."\n";
if (preg_match('/Гарантия с\s*(\d+)/u', $h, $m)) echo "guarantee_from={$m[1]}\n";
if (preg_match_all('/update\?id=(\d+)/', $h, $ids)) {
    $uniq = array_unique($ids[1]);
    echo "update links count=".count($uniq)." sample=".implode(',', array_slice($uniq,0,15))."\n";
}
