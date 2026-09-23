<?php
$h = file_get_contents(__DIR__.'/storage/app/kp_card_otz.html');
// search near CustomerRequest for feedback / otzyv / is_fb
$patterns = [
    '/CustomerRequest\[[^\]]{0,40}\]/i',
    '/name="[^"]*(?:fb|feedback|otzyv|review)[^"]*"/i',
    '/id="[^"]*(?:[Ff]b|[Oo]tz)[^"]*"/',
    '/class="[^"]*(?:fb|feedback|otz)[^"]*"/i',
];
foreach (['is_fb','fb_req','need_call','отзыв','Отз','only_is_fb','__tIs'] as $n) {
    $offset = 0;
    $c = 0;
    while (($p = stripos($h, $n, $offset)) !== false && $c < 5) {
        $chunk = substr($h, max(0, $p - 60), 160);
        if (!str_contains($chunk, 'dropdown') && !str_contains($chunk, 'nav-')) {
            echo "=== $n @$p ===\n".preg_replace('/\s+/', ' ', $chunk)."\n";
            $c++;
        }
        $offset = $p + strlen($n);
    }
}

// look for badge near status
if (preg_match('/Статус:[\s\S]{0,500}/u', $h, $m)) {
    echo "STATUS AREA: ".preg_replace('/\s+/', ' ', strip_tags($m[0]))."\n";
    echo "STATUS HTML: ".preg_replace('/\s+/', ' ', substr($m[0],0,400))."\n";
}

// list filter param only_is_fb_req - maybe hidden field
if (preg_match_all('/only_is_fb[^"\']*/i', $h, $mm)) {
    echo "only_is_fb: ".implode(',', array_unique($mm[0]))."\n";
}
