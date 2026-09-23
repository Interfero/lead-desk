<?php
$h = file_get_contents(__DIR__.'/storage/app/kp_card_hist.html');
libxml_use_internal_errors(true);
$dom = new DOMDocument();
$dom->loadHTML('<?xml encoding="UTF-8">'.$h);
$xpath = new DOMXPath($dom);
$grid = $xpath->query('//*[@id="customer-requests-grid"]')->item(0);
if (!$grid) { echo "no grid\n"; exit; }
echo "grid tag=".$grid->nodeName." class=".$grid->getAttribute('class')."\n";
$tables = $xpath->query('.//table', $grid);
echo "tables=".$tables->length."\n";
for ($i=0; $i<min(5,$tables->length); $i++) {
    $t = $tables->item($i);
    echo "T$i class=".$t->getAttribute('class')." id=".$t->getAttribute('id')."\n";
    $trs = $xpath->query('./tbody/tr|./tr', $t);
    echo "  direct_ish tr via ./tbody/tr = ".$xpath->query('./tbody/tr', $t)->length."\n";
}
$main = $xpath->query('.//table[contains(@class,"kv-grid-table")]', $grid);
echo "kv-grid-table=".$main->length."\n";
if ($main->length) {
    $trs = $xpath->query('./tbody/tr', $main->item(0));
    echo "main tbody tr=".$trs->length."\n";
    foreach ($trs as $i => $tr) {
        if ($i>2) break;
        $key = $tr->getAttribute('data-key');
        $tds = $xpath->query('./td', $tr);
        echo "row key=$key tds=".$tds->length." text=".substr(preg_replace('/\s+/',' ', $tr->textContent),0,80)."\n";
    }
}
