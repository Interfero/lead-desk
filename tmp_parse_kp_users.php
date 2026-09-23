<?php
require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$html = file_get_contents('/tmp/kp_user_index.html');
if ($html === false || $html === '') {
    $html = file_get_contents(__DIR__ . '/../tmp/kp_user_index.html');
}
// Prefer the probe output path on server
if (!is_file('/tmp/kp_user_index.html')) {
    // re-fetch quick via previous file in project if any
}

$prev = libxml_use_internal_errors(true);
$dom = new DOMDocument();
$dom->loadHTML('<?xml encoding="UTF-8">'.$html);
libxml_clear_errors();
libxml_use_internal_errors($prev);
$xpath = new DOMXPath($dom);

$rows = $xpath->query('//table//tr');
$out = [];
foreach ($rows as $i => $tr) {
    if ($i === 0) continue; // header
    $tds = $xpath->query('./td', $tr);
    if (!$tds || $tds->length < 5) continue;
    $cells = [];
    foreach ($tds as $td) {
        $cells[] = trim(preg_replace('/\s+/u', ' ', $td->textContent));
    }
    $id = $cells[0] ?? '';
    if (!preg_match('/^\d+$/', $id)) continue;
    $href = '';
    $links = $xpath->query('.//a[@href]', $tr);
    if ($links && $links->length) {
        $href = $links->item(0)->getAttribute('href');
    }
    $out[] = [
        'id' => $id,
        'email' => $cells[5] ?? '',
        'name' => $cells[6] ?? '',
        'role' => $cells[8] ?? '',
        'city' => $cells[9] ?? '',
        'status' => $cells[10] ?? '',
        'href' => $href,
        'cells' => $cells,
    ];
    if (count($out) >= 25) break;
}

echo json_encode($out, JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT)."\n";
echo "total_data_rows_sample=".count($out)."\n";

// unique roles on page
$roles = [];
foreach ($out as $r) { $roles[$r['role']] = ($roles[$r['role']] ?? 0) + 1; }
echo "roles=".json_encode($roles, JSON_UNESCAPED_UNICODE)."\n";

// pagination?
if (preg_match_all('/page=(\d+)/', $html, $pm)) {
    echo "pages=".json_encode(array_values(array_unique($pm[1])))."\n";
}
if (preg_match('/Всего[:\s]*(\d+)/u', $html, $tm) || preg_match('/total[^>]*>[\s]*(\d+)/i', $html, $tm)) {
    echo "total_hint=".$tm[1]."\n";
}
