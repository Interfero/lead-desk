<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// CRM1 — каждую минуту (lite API). Mutex общий, чтобы не пересекаться с full.
Schedule::command('desk:sync --type=crm1_api')
    ->everyMinute()
    ->withoutOverlapping(5);

// КП — каждые 2 минуты (медленно по филиалам).
Schedule::command('desk:sync --type=crm2_http')
    ->everyTwoMinutes()
    ->withoutOverlapping(10);

// KP history: the worker processes at most ten pages per minute and never blocks a browser request.
Schedule::command('desk:history-sync:work --limit=10')
    ->everyMinute()
    ->withoutOverlapping(15);

Schedule::command('desk:history-sync:queue-daily')
    ->dailyAt('02:20')
    ->withoutOverlapping(10);

// Мастера КП → CRM и селект карточки (найм / увольнение).
Schedule::command('desk:sync-masters')
    ->everyTenMinutes()
    ->withoutOverlapping(20);

// Полная пересинхронизация LC раз в 30 мин (подчищает «зависшие» открытые в кэше).
Schedule::command('desk:sync --full --type=crm1_api')
    ->everyThirtyMinutes()
    ->withoutOverlapping(5);
