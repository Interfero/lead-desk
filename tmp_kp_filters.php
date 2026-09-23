<?php
$html = file_get_contents('/tmp/kp_user_index.html');
preg_match_all('/name="([^"]+)"/', $html, $m);
$names = array_unique($m[1]);
sort($names);
foreach ($names as $n) {
    if (stripos($n, 'User') !== false || stripos($n, 'role') !== false || stripos($n, 'search') !== false || stripos($n, 'page') !== false) {
        echo $n, "\n";
    }
}
echo "---\n";
if (preg_match('/<ul[^>]*class="[^"]*pagination[^"]*"[^>]*>(.*?)<\/ul>/is', $html, $pm)) {
    echo "pagination_html_len=".strlen($pm[1])."\n";
    preg_match_all('/page=(\d+)/', $pm[1], $pages);
    echo "pages=".json_encode(array_values(array_unique($pages[1])))."\n";
}
if (preg_match('/summary[^>]*>.*?(\d+).*?(\d+).*?(\d+)/us', $html, $sm)) {
    echo "summary=".json_encode($sm)."\n";
}
// try role filter options
if (preg_match('/name="[^"]*role[^"]*"[^>]*>(.*?)<\/select>/is', $html, $rm)) {
    preg_match_all('/<option[^>]*value="([^"]*)"[^>]*>([^<]*)/i', $rm[1], $opts, PREG_SET_ORDER);
    foreach ($opts as $o) echo "role_opt {$o[1]} => ".trim($o[2])."\n";
}
