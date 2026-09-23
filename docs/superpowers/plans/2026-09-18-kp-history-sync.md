# KP History Synchronization Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Import a selected 7/30/90-day window of KP city history asynchronously, preserve closed orders, and reconcile the last seven days for history-enabled cities.

**Architecture:** Persistent `desk_history_sync_runs` rows carry the city, date window and next KP page. A page-oriented KP adapter and a scheduled worker process bounded pages, upserting without pruning. The settings page creates idempotent runs and exposes their progress.

**Tech Stack:** Laravel 12, PHP 8.2, Eloquent/MySQL, Blade, Artisan scheduler, PHPUnit/Pest through `php artisan test`.

**Spec:** `docs/superpowers/specs/2026-09-18-kp-history-sync-design.md`

## Global Constraints

- Do not alter CRM1 behavior or its every-minute/full synchronization schedules.
- Keep the existing two-minute KP active-list synchronization lightweight.
- The history date window is exactly `DD.MM.YYYY 00:00 - DD.MM.YYYY 23:59` in KP local time.
- Include KP status IDs `1, 4, 8, 9, 32, 1001, 1002, 1003, 64, 128, 1020, 20, 3, 48` in every history page request.
- History page writes must never execute the cache-pruning branch.
- Reuse `DeskAccess::canManageCrm2Settings` and `DeskAccess::canAccessCity` for every settings action.
- Keep user-facing copy in Russian and do not expose KP credentials or raw HTML errors.
- This deployed copy has no Git metadata: do not run commit commands.

---

## File Structure

- `database/migrations/2026_09_18_000000_create_desk_history_sync_runs_table.php` — persistent work queue schema.
- `app/Models/DeskHistorySyncRun.php` — run state constants, casts and query scopes.
- `app/Adapters/Crm2HttpAdapter.php` — fetch and parse one filtered KP history page.
- `app/Services/DeskHistorySyncService.php` — create/deduplicate runs, process pages, complete/fail runs and queue daily reconciliations.
- `app/Console/Commands/DeskHistorySyncWorkCommand.php` — bounded minute worker.
- `app/Console/Commands/DeskHistorySyncQueueDailyCommand.php` — create one daily rolling seven-day run per enabled city.
- `app/Http/Controllers/DeskSettingsController.php` — start a run and load run status for the page.
- `routes/web.php` — protected POST history route.
- `routes/console.php` — minute worker and daily enqueue schedules.
- `resources/views/desk/settings.blade.php` — three history buttons and run state/progress.
- `tests/Unit/Crm2HttpAdapterPaginationTest.php` — filtered page/pager contract.
- `tests/Feature/DeskHistorySyncServiceTest.php` — persistence, non-pruning writes, retries and daily de-duplication.
- `tests/Feature/DeskHistorySyncSettingsTest.php` — route authorization and run creation response.

### Task 1: KP filtered-page contract

**Files:**
- Modify: `tests/Unit/Crm2HttpAdapterPaginationTest.php`
- Modify: `app/Adapters/Crm2HttpAdapter.php:91-121, 1740-1950`

**Interfaces:**
- Produces `Crm2HttpAdapter::fetchHistoryPage(\DateTimeInterface $from, \DateTimeInterface $to, int $page): array{orders:list<array<string,mixed>>,next_page:?int}`.
- `DeskHistorySyncService` consumes `orders` and `next_page`; `null` means the KP pager has no enabled next link.

- [ ] **Step 1: Replace the initial regression test with a filtered, two-page KP fixture**

```php
public function test_history_page_includes_all_statuses_and_returns_the_next_kp_page(): void
{
    Http::fake(function (Request $request) {
        parse_str(parse_url($request->url(), PHP_URL_QUERY) ?? '', $query);
        $this->assertSame('11.09.2026 00:00 - 18.09.2026 23:59', $query['CustomerRequestSearch']['dates']);
        $this->assertSame([1, 4, 8, 9, 32, 1001, 1002, 1003, 64, 128, 1020, 20, 3, 48], array_map('intval', $query['CustomerRequestSearch']['statuses']));

        return Http::response($this->ordersPage(
            $query['page'] ?? 1,
            (int) ($query['page'] ?? 1) === 1
        ));
    });

    $first = $adapter->fetchHistoryPage(CarbonImmutable::parse('2026-09-11'), CarbonImmutable::parse('2026-09-18'), 1);
    $second = $adapter->fetchHistoryPage(CarbonImmutable::parse('2026-09-11'), CarbonImmutable::parse('2026-09-18'), 2);

    $this->assertSame(['1001'], array_column($first['orders'], 'external_id'));
    $this->assertSame(2, $first['next_page']);
    $this->assertSame(['1002'], array_column($second['orders'], 'external_id'));
    $this->assertNull($second['next_page']);
}
```

The fixture must use a Yii pager with `<li class="page-item next">` and
`data-page="1"` on the enabled next link. The production regression this test
catches is any change that drops final statuses, misformats dates, or stops at
the first page.

- [ ] **Step 2: Run the focused test and verify RED**

Run: `php artisan test --compact tests/Unit/Crm2HttpAdapterPaginationTest.php`

Expected: FAIL because `fetchHistoryPage` is undefined.

- [ ] **Step 3: Implement the minimal page method and pager parser**

```php
public const HISTORY_STATUS_IDS = [1, 4, 8, 9, 32, 1001, 1002, 1003, 64, 128, 1020, 20, 3, 48];

public function fetchHistoryPage(\DateTimeInterface $from, \DateTimeInterface $to, int $page): array
{
    if ($page < 1) {
        throw new RuntimeException('Некорректная страница истории KP.');
    }

    $query = http_build_query([
        'CustomerRequestSearch' => [
            'dates' => $from->format('d.m.Y').' 00:00 - '.$to->format('d.m.Y').' 23:59',
            'statuses' => self::HISTORY_STATUS_IDS,
        ],
        'page' => $page,
        'per-page' => 50,
    ]);
    $body = $this->getOrdersListHtml($query);

    return [
        'orders' => $this->withHistoryCity($this->parseOrdersHtml($body)),
        'next_page' => $this->nextHistoryPage($body, $page),
    ];
}
```

Extract the existing authenticated list GET and city decoration from
`fetchOrders()` into `getOrdersListHtml(string $query = '')` and
`withHistoryCity(array $orders)`. `nextHistoryPage()` must inspect only the
enabled `li.next a[data-page]` in the pagination list, convert the zero-based
`data-page` value to one-based, and throw if the resulting page is not greater
than the requested page. Do not infer a next page from row count.

- [ ] **Step 4: Run the focused test and verify GREEN**

Run: `php artisan test --compact tests/Unit/Crm2HttpAdapterPaginationTest.php`

Expected: PASS with the first-page and terminal-page assertions.

- [ ] **Step 5: Add malformed-pager coverage**

```php
public function test_history_page_rejects_a_non_advancing_pager(): void
{
    Http::fake(['https://kp.test/*' => Http::response($this->ordersPageWithNextDataPage(0))]);

    $this->expectException(RuntimeException::class);
    $this->expectExceptionMessage('Некорректная следующая страница истории KP.');

    $adapter->fetchHistoryPage(CarbonImmutable::parse('2026-09-11'), CarbonImmutable::parse('2026-09-18'), 1);
}
```

- [ ] **Step 6: Run both adapter tests**

Run: `php artisan test --compact tests/Unit/Crm2HttpAdapterPaginationTest.php`

Expected: PASS with both pagination scenarios.

### Task 2: Persistent history-run model and service

**Files:**
- Create: `database/migrations/2026_09_18_000000_create_desk_history_sync_runs_table.php`
- Create: `app/Models/DeskHistorySyncRun.php`
- Create: `app/Services/DeskHistorySyncService.php`
- Create: `tests/Feature/DeskHistorySyncServiceTest.php`

**Interfaces:**
- Consumes `Crm2HttpAdapter::fetchHistoryPage()` from Task 1 and
  `DeskSyncService::upsertHistoryOrders(CrmConnection $connection, array $orders, int $cityId): int`.
- Produces `DeskHistorySyncService::startManualRun(int $cityId, int $days, int $userId): DeskHistorySyncRun`,
  `processQueuedPages(int $limit = 10): int`, and `queueDailyRuns(): int`.

- [ ] **Step 1: Write a failing service test for idempotent creation**

```php
public function test_start_manual_run_reuses_an_active_city_run(): void
{
    $first = $this->service->startManualRun(14, 30, 7);
    $second = $this->service->startManualRun(14, 30, 8);

    $this->assertSame($first->id, $second->id);
    $this->assertSame(30, $first->days);
    $this->assertSame(DeskHistorySyncRun::STATE_QUEUED, $first->state);
    $this->assertDatabaseCount('desk_history_sync_runs', 1);
}
```

Use `RefreshDatabase`, create an active `crm2_http` connection and a credential
for city 14. The break this catches is duplicate KP traffic after repeated
button clicks.

- [ ] **Step 2: Run the test and verify RED**

Run: `php artisan test --compact tests/Feature/DeskHistorySyncServiceTest.php --filter=start_manual_run_reuses`

Expected: FAIL because the model/table/service do not exist.

- [ ] **Step 3: Add schema, model and creation method**

Create the table with these exact columns:

```php
$table->id();
$table->foreignId('crm_id')->constrained('crm_connections')->cascadeOnDelete();
$table->unsignedBigInteger('city_id')->index();
$table->unsignedSmallInteger('days');
$table->date('period_from');
$table->date('period_to');
$table->unsignedInteger('next_page')->default(1);
$table->enum('state', ['queued', 'running', 'completed', 'failed'])->default('queued')->index();
$table->boolean('automatic')->default(false)->index();
$table->unsignedInteger('pages_processed')->default(0);
$table->unsignedInteger('orders_upserted')->default(0);
$table->timestamp('last_processed_at')->nullable()->index();
$table->text('error')->nullable();
$table->unsignedBigInteger('created_by')->nullable();
$table->timestamp('started_at')->nullable();
$table->timestamp('finished_at')->nullable();
$table->timestamps();
$table->index(['city_id', 'state']);
```

In `startManualRun`, accept only `7`, `30`, `90`; obtain the configured KP
connection and city credential; throw `RuntimeException('Сначала сохраните логин и пароль')` when missing.
Within `DB::transaction()`, lock active city runs and return one if found;
otherwise create a manual queued run whose inclusive start date is
`today()->subDays($days - 1)` and whose end date is `today()`.

- [ ] **Step 4: Run the creation test and verify GREEN**

Run: `php artisan test --compact tests/Feature/DeskHistorySyncServiceTest.php --filter=start_manual_run_reuses`

Expected: PASS.

- [ ] **Step 5: Write failing tests for page persistence and non-pruning writes**

```php
public function test_worker_upserts_a_history_page_without_deleting_existing_open_cache_rows(): void
{
    DeskOrderCache::query()->create([
        'crm_id' => $this->crm->id,
        'city_id' => 14,
        'external_id' => 'keep-open',
        'status' => 'in_progress',
        'raw_status' => 'in_progress',
    ]);
    $run = DeskHistorySyncRun::query()->create([
        'crm_id' => $this->crm->id,
        'city_id' => 14,
        'days' => 30,
        'period_from' => '2026-08-19',
        'period_to' => '2026-09-17',
        'next_page' => 1,
        'state' => DeskHistorySyncRun::STATE_QUEUED,
    ]);
    $this->fakeAdapter->returnsPage(1, [['external_id' => 'closed-1002', 'raw_status' => 'completed']], null);

    $this->service->processQueuedPages(1);

    $this->assertDatabaseHas('orders_cache', ['external_id' => 'keep-open']);
    $this->assertDatabaseHas('orders_cache', ['external_id' => 'closed-1002', 'raw_status' => 'completed']);
    $this->assertDatabaseHas('desk_history_sync_runs', ['id' => $run->id, 'state' => 'completed', 'pages_processed' => 1]);
}
```

Bind a test adapter factory into the container; assert database outcomes, not
method-call counts.

- [ ] **Step 6: Run the worker test and verify RED**

Run: `php artisan test --compact tests/Feature/DeskHistorySyncServiceTest.php --filter=worker_upserts`

Expected: FAIL because page processing is absent.

- [ ] **Step 7: Implement one-page-at-a-time processing**

Add a public `DeskSyncService::upsertHistoryOrders()` wrapper that calls the
existing `upsertOrders($connection, $orders, false, $cityId)` and returns its
`upserted` count. It must never pass `true` as the third argument and must not
call `hydrateIncompleteKpOrders()`.

`processQueuedPages($limit)` must claim one oldest queued/running run at a
time, call `fetchHistoryPage($run->period_from, $run->period_to, $run->next_page)`,
upsert its rows, then persist counters and either `next_page` or a completed
state. On any `Throwable`, persist the safe `KpHttpError::message($e)`, set
`failed` and `finished_at`, and continue to the next run. A completed/failed
run is never processed again.

- [ ] **Step 8: Run the service test file and verify GREEN**

Run: `php artisan test --compact tests/Feature/DeskHistorySyncServiceTest.php`

Expected: PASS, including idempotency and preservation assertions.

- [ ] **Step 9: Add and prove daily de-duplication**

```php
public function test_daily_queue_creates_one_7_day_run_only_for_history_enabled_cities(): void
{
    $this->createHistoryRun(14, DeskHistorySyncRun::STATE_COMPLETED, false);
    $this->createHistoryRun(15, DeskHistorySyncRun::STATE_COMPLETED, false);
    $this->createHistoryRun(15, DeskHistorySyncRun::STATE_QUEUED, true);

    $this->assertSame(1, $this->service->queueDailyRuns());
    $this->assertDatabaseHas('desk_history_sync_runs', ['city_id' => 14, 'days' => 7, 'automatic' => true, 'state' => 'queued']);
    $this->assertDatabaseCount('desk_history_sync_runs', 4);
}
```

Implement `queueDailyRuns()` by selecting distinct cities with a completed
manual run, verifying their credentials remain configured, and transactionally
creating a 7-day automatic run only when no queued/running run exists for that
city. Run the focused test once red and once green.

### Task 3: Commands and scheduler

**Files:**
- Create: `app/Console/Commands/DeskHistorySyncWorkCommand.php`
- Create: `app/Console/Commands/DeskHistorySyncQueueDailyCommand.php`
- Modify: `routes/console.php:14-30`
- Modify: `tests/Feature/DeskHistorySyncServiceTest.php`

**Interfaces:**
- Consumes `DeskHistorySyncService::processQueuedPages(10)` and `queueDailyRuns()` from Task 2.
- Produces Artisan commands `desk:history-sync:work {--limit=10}` and `desk:history-sync:queue-daily`.

- [ ] **Step 1: Write failing Artisan command assertions**

```php
public function test_history_worker_processes_the_requested_bounded_number_of_pages(): void
{
    $this->createQueuedRuns(3);

    $this->artisan('desk:history-sync:work', ['--limit' => 2])
        ->expectsOutput('Обработано страниц истории KP: 2')
        ->assertExitCode(0);

    $this->assertSame(1, DeskHistorySyncRun::query()->where('state', 'queued')->count());
}
```

- [ ] **Step 2: Run it and verify RED**

Run: `php artisan test --compact tests/Feature/DeskHistorySyncServiceTest.php --filter=history_worker_processes`

Expected: FAIL because the command is not registered.

- [ ] **Step 3: Implement commands and schedules**

`DeskHistorySyncWorkCommand` validates `--limit` as `1..10`, calls the service,
prints exactly `Обработано страниц истории KP: {$count}`, and exits successfully
even when a page failure was persisted on its run.

`DeskHistorySyncQueueDailyCommand` calls `queueDailyRuns()`, prints exactly
`Создано ежедневных задач истории KP: {$count}`, and exits successfully.

Add these schedules:

```php
Schedule::command('desk:history-sync:work --limit=10')
    ->everyMinute()
    ->withoutOverlapping(15);

Schedule::command('desk:history-sync:queue-daily')
    ->dailyAt('02:20')
    ->withoutOverlapping(10);
```

Do not alter the existing CRM1/KP schedules.

- [ ] **Step 4: Run command and schedule-adjacent tests**

Run: `php artisan test --compact tests/Feature/DeskHistorySyncServiceTest.php`

Expected: PASS.

### Task 4: Settings actions, authorization and status UI

**Files:**
- Modify: `routes/web.php:32-37`
- Modify: `app/Http/Controllers/DeskSettingsController.php:16-35, 113-128`
- Modify: `resources/views/desk/settings.blade.php:61-119`
- Create: `tests/Feature/DeskHistorySyncSettingsTest.php`

**Interfaces:**
- Consumes `DeskHistorySyncService::startManualRun(int $cityId, int $days, int $userId)`.
- Produces POST route `desk.settings.history-sync` with URI
  `/desk/settings/history-sync/{cityId}/{days}` where `days` is constrained to `7|30|90`.

- [ ] **Step 1: Write a failing authorized-route test**

```php
public function test_city_manager_can_queue_a_30_day_history_sync_for_an_allowed_city(): void
{
    $this->withSession(['desk_user' => ['id' => 7, 'roles' => ['developer'], 'city_ids' => [14]]])
        ->post(route('desk.settings.history-sync', ['cityId' => 14, 'days' => 30]))
        ->assertRedirect(route('desk.settings'))
        ->assertSessionHas('success', 'История KP за 30 дн. поставлена в очередь.');

    $this->assertDatabaseHas('desk_history_sync_runs', ['city_id' => 14, 'days' => 30, 'state' => 'queued']);
}
```

Add a companion test asserting that a user without city 14 receives 403 and no
run row. The production break these tests catch is bypassing city scope from a
settings POST.

- [ ] **Step 2: Run the settings tests and verify RED**

Run: `php artisan test --compact tests/Feature/DeskHistorySyncSettingsTest.php`

Expected: FAIL because the route/controller method is absent.

- [ ] **Step 3: Implement route, controller and page data**

Add the constrained route inside the existing desk authenticated group. Add
`queueHistory(Request $request, int $cityId, int $days, DeskHistorySyncService $history)`:

```php
$user = $request->session()->get('desk_user');
abort_unless(DeskAccess::canManageCrm2Settings($user), 403);
abort_unless(DeskAccess::canAccessCity($user, $cityId), 403);
$history->startManualRun($cityId, $days, (int) ($user['id'] ?? 0));

return redirect()->route('desk.settings')
    ->with('success', "История KP за {$days} дн. поставлена в очередь.");
```

In `index()`, retrieve the newest run per visible city and pass it as
`$historyRuns` keyed by `city_id`; do not load run rows from inaccessible
cities.

- [ ] **Step 4: Implement the status and three buttons in Blade**

Inside the existing credential-only settings actions, add one status line and
three standalone CSRF POST forms:

```blade
@if($historyRun = ($historyRuns[$city['id']] ?? null))
    <p class="muted" style="margin:8px 0 0;font-size:12px;">
        История KP: {{ $historyRun->stateLabel() }} · страниц {{ $historyRun->pages_processed }}, заявок {{ $historyRun->orders_upserted }}
    </p>
@endif
<div class="settings-actions">
    @foreach([7, 30, 90] as $days)
        <form method="POST" action="{{ route('desk.settings.history-sync', ['cityId' => $city['id'], 'days' => $days]) }}">
            @csrf
            <button class="btn btn-sm" type="submit">История: {{ $days }} дн.</button>
        </form>
    @endforeach
</div>
```

`stateLabel()` must return `В очереди`, `Выполняется`, `Готово`, or `Ошибка`.
For `failed`, append only the sanitized `error` text already persisted by the
service. Do not disable the normal city synchronization button.

- [ ] **Step 5: Run settings tests and render smoke test**

Run: `php artisan test --compact tests/Feature/DeskHistorySyncSettingsTest.php`

Expected: PASS for the allowed and denied requests.

Run: `php artisan view:cache`

Expected: completes without Blade compilation errors.

### Task 5: Full verification and controlled Pskov import

**Files:**
- Modify: `docs/superpowers/specs/2026-09-18-kp-history-sync-design.md` only if implementation requires a documented deviation; otherwise no source edit.

**Interfaces:**
- Consumes all previous tasks.
- Produces verified migration, test evidence and a bounded Pskov history run.

- [ ] **Step 1: Run all relevant tests**

Run: `php artisan test --compact tests/Unit/Crm2HttpAdapterPaginationTest.php tests/Feature/DeskHistorySyncServiceTest.php tests/Feature/DeskHistorySyncSettingsTest.php`

Expected: all new tests pass.

- [ ] **Step 2: Run the complete suite and classify the known baseline failure**

Run: `php artisan test --compact`

Expected: all new tests pass; the pre-existing `Tests\\Feature\\ExampleTest::test_the_application_returns_a_successful_response` may still fail because `/` redirects to login with 302. Do not represent that legacy failure as caused by this work.

- [ ] **Step 3: Apply the migration and verify registration**

Run: `php artisan migrate --force`

Run: `php artisan about --only=environment`

Run: `php artisan schedule:list | rg 'desk:history-sync'`

Expected: migration succeeds; production environment is reported; both new schedules are listed.

- [ ] **Step 4: Start a controlled Pskov 30-day run through the service/route-equivalent command path**

Use the authenticated settings button for city 14 and 30 days. Verify one queued
row is created, then invoke `php artisan desk:history-sync:work --limit=10`
once. Confirm the run's `pages_processed` and `orders_upserted` increased and
that no existing Pskov cache row was deleted.

- [ ] **Step 5: Verify the user-reported result after the run completes**

Query only aggregate/status data for `master_name = 'Тугай Никита'` and city 14:

```php
DeskOrderCache::query()
    ->where('crm_id', 2)
    ->where('city_id', 14)
    ->where('master_name', 'Тугай Никита')
    ->selectRaw('raw_status, COUNT(*) AS total')
    ->groupBy('raw_status')
    ->pluck('total', 'raw_status');
```

Expected: the count of final statuses is greater than the pre-change cache
baseline of three, provided KP returns more final orders for the selected
window. Report only aggregate counts and no customer data.

## Plan Self-Review

- Spec coverage: Task 1 implements native KP filters/paging; Task 2 implements persistent progress, non-pruning writes, failure persistence and daily de-duplication; Task 3 makes it bounded/background; Task 4 implements authorized UI; Task 5 verifies the reported Pskov case.
- Placeholder scan: no unresolved implementation placeholders are present.
- Interface consistency: the adapter returns `orders`/`next_page`; the history service consumes it; commands and controller call the service's public methods; Blade reads the model's `stateLabel()`.
