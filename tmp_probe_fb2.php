<?php

$html = file_get_contents(__DIR__.'/storage/app/kp_list_live.html');

// find rows with NeedCall / Late / OnWay
preg_match_all('/<tr([^>]*class="[^"]*(?:Late|NeedCall|OnWay)[^"]*"[^>]*)>([\s\S]*?)<\/tr>/i', $html, $rows, PREG_SET_ORDER);
echo 'special rows='.count($rows)."\n";
foreach (array_slice($rows, 0, 8) as $r) {
    preg_match('/class="([^"]*)"/', $r[1], $cm);
    preg_match('/data-key="(\d+)"/', $r[1], $km);
    $status = '';
    if (preg_match('/col__req_status[^>]*>([\s\S]*?)<\/td>/i', $r[2], $sm)) {
        $status = trim(preg_replace('/\s+/u', ' ', strip_tags($sm[1])));
    }
    $time = '';
    if (preg_match('/col__openedAt[^>]*>([\s\S]*?)<\/td>/i', $r[2], $tm)
        || preg_match('/col__date[^>]*>([\s\S]*?)<\/td>/i', $r[2], $tm)) {
        $time = trim(preg_replace('/\s+/u', ' ', strip_tags($tm[1])));
    }
    echo '#'.($km[1] ?? '?').' cls='.preg_replace('/\s+/', ' ', $cm[1] ?? '')." status={$status} time={$time}\n";
}

// Отз: how encoded — only in status text?
$otzRows = 0;
preg_match_all('/<tr([^>]*)>([\s\S]*?)<\/tr>/i', $html, $all, PREG_SET_ORDER);
foreach ($all as $r) {
    if (str_contains($r[2], 'Отз')) {
        $otzRows++;
        if ($otzRows <= 3) {
            preg_match('/data-key="(\d+)"/', $r[1], $km);
            preg_match('/class="([^"]*)"/', $r[1], $cm);
            $status = '';
            if (preg_match('/col__req_status[^>]*>([\s\S]*?)<\/td>/i', $r[2], $sm)) {
                $status = trim(preg_replace('/\s+/u', ' ', strip_tags($sm[1])));
            }
            echo "OTZ #".($km[1] ?? '?')." cls=".trim(preg_replace('/\s+/', ' ', $cm[1] ?? ''))." status={$status}\n";
        }
    }
}
echo "otzRows={$otzRows}\n";

// card history + feedback flags
$card = file_get_contents(__DIR__.'/storage/app/kp_card_hist.html');
foreach (['is_fb_req', 'only_is_fb', 'fb_req', 'has_fb', '__tFeedback', 'cr_feedback', 'feedback_id'] as $n) {
    if (stripos($card, $n) !== false) {
        echo "card field hit {$n}\n";
    }
}
if (preg_match_all('/name="[^"]*fb[^"]*"|id="[^"]*fb[^"]*"|__t[A-Za-z]*[Ff]b[^"]*"/', $card, $mm)) {
    echo "fb attrs:\n";
    foreach (array_unique($mm[0]) as $x) {
        echo "  {$x}\n";
    }
}

// look near Отз on card h1/status
if (preg_match('/Статус:[\s\S]{0,200}/u', $card, $sm)) {
    echo 'h1 status: '.trim(preg_replace('/\s+/u', ' ', strip_tags($sm[0])))."\n";
}
