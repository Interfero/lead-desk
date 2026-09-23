<?php
$dir = '/var/www/lead-desk/storage/framework/views';
foreach (glob($dir.'/*.php') as $f) {
    $c = file_get_contents($f);
    $hits = [];
    foreach (['desk-cities-picker', 'desk-cities-filter', 'Города для просмотра', 'desk-city-chip', 'Найти город', 'filter_cities'] as $n) {
        if (str_contains($c, $n)) $hits[] = $n;
    }
    if ($hits) {
        echo basename($f).': '.implode(', ', $hits)."\n";
    }
}
echo "blade mtime: ".date('c', filemtime('/var/www/lead-desk/resources/views/desk/index.blade.php'))."\n";
echo "showCityPicker in blade: ".(str_contains(file_get_contents('/var/www/lead-desk/resources/views/desk/index.blade.php'), 'showCityPicker') ? 'yes':'no')."\n";
echo "old chips card: ".(str_contains(file_get_contents('/var/www/lead-desk/resources/views/desk/index.blade.php'), 'desk-cities-filter card') ? 'YES OLD':'no')."\n";
