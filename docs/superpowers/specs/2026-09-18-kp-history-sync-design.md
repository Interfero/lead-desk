# KP History Synchronization Design

## Goal

Allow an authorized user to start a bounded historical import for one KP city
for the last 7, 30, or 90 calendar days, while ensuring imported closed orders
remain visible in Lead Desk and newly closed orders are reconciled afterwards.

## Scope

- KP (`crm2_http`) only; CRM1 is unchanged.
- A city-level settings UI starts a 7/30/90-day history run and shows its
  current or most recent state.
- A history run fetches the KP list with its native date and status filters,
  follows every page in that result, and upserts the rows into `orders_cache`.
- History runs include every KP status exposed by the list filter, including
  completed and cancellation statuses.
- Every successfully history-enabled city receives a daily rolling 7-day
  reconciliation.

## Non-goals

- Importing all-time KP history.
- Changing the existing two-minute active-order synchronization contract.
- Deleting cache rows because they are absent from a limited-date result.
- Running a long KP import inside a browser request or Laravel's synchronous
  queue driver.

## Architecture

### Persistent runs

Add `desk_history_sync_runs` as a persistent work queue. A row represents one
city and date window and stores: city and CRM identifiers, requested period,
range boundaries, the next KP page, state (`queued`, `running`, `completed`,
`failed`), counters, error text, timestamps, and whether it is an automatic
daily reconciliation.

At most one queued or running history run may exist for a city. Starting a
manual run while one exists returns the existing run instead of issuing a
second set of KP requests. Completing any manual run marks that city as
history-enabled through its completed run record; the daily scheduler uses
those records to create rolling 7-day reconciliation runs.

### KP adapter

Extend `Crm2HttpAdapter` with a page-oriented list read that accepts a date
range, all KP status IDs, and a requested page. It must build the KP GET query
using the exact `CustomerRequestSearch[dates]` format:
`DD.MM.YYYY 00:00 - DD.MM.YYYY 23:59`.

The adapter parses the native Yii pager and returns both normalized rows and a
next page indicator. It must reject a malformed/non-advancing pager with a
clear exception. The existing `fetchOrders()` method remains the lightweight
active-list read used by the two-minute synchronization.

### Worker and scheduling

A new Artisan worker processes one KP page for one queued/running run per
invocation. It locks the run/city, persists progress after every successfully
upserted page, and releases the lock. The scheduler invokes it every minute;
therefore requests are bounded and recover after a process restart.

The daily scheduler only queues rolling 7-day runs for cities with a completed
manual history run and no active history run. It does not create duplicates.

### Cache safety

History pages are passed to the existing cache upsert flow in non-pruning mode.
They never use the `full` deletion branch: a date-filtered KP page cannot be a
complete representation of a city. The existing normal sync continues to
reconcile active cached rows that disappear from KP, so their final status is
recorded rather than lost.

## UI and authorization

The city settings area that already owns per-city KP checks and synchronization
gets three POST actions: 7, 30, and 90 days. They reuse
`DeskAccess::canManageCrm2Settings` and `DeskAccess::canAccessCity`.

Each city shows active state/progress or the outcome and timestamp of its most
recent history run. Buttons use the existing settings styling and remain safe
to press repeatedly because run creation is idempotent per city.

## Failure handling

- A KP request failure marks only its run as `failed` with a safe, user-facing
  error; completed cache pages remain intact.
- A malformed pager, duplicate/non-advancing next page, or page limit breach
  fails the run rather than retrying indefinitely.
- The next manual button press creates a new run after a failed or completed
  run.
- Normal KP synchronization is independent and continues even if a history
  run fails.

## Verification

Tests will cover page traversal, exact date/status query construction,
non-pruning cache writes, idempotent run creation, worker progress, failure
recording, daily run de-duplication, and city/role authorization. A controlled
Pskov 30-day run will be used after deployment to verify that KP orders for
Тугай Никита appear in the closed list.
