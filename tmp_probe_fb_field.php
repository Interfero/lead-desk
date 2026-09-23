<?php
$h = file_get_contents(__DIR__.'/storage/app/kp_card_hist.html');
$pos = stripos($h, 'cr_feedback');
echo "pos=$pos\n";
if ($pos !== false) {
    echo substr($h, max(0, $pos - 80), 250)."\n";
}
// find CustomerRequest feedback fields
if (preg_match_all('/CustomerRequest\[[^\]]*fb[^\]]*\][^>]{0,80}/i', $h, $m)) {
    foreach (array_unique($m[0]) as $x) echo "FIELD $x\n";
}
if (preg_match_all('/name="([^"]*feedback[^"]*)"[^>]{0,100}/i', $h, $m2)) {
    foreach (array_unique($m2[0]) as $x) echo "NAME $x\n";
}
if (preg_match_all('/id="(__t[^"]*[Ff]b[^"]*)"[^>]{0,100}/', $h, $m3)) {
    foreach ($m3[0] as $x) echo "ID $x\n";
}
