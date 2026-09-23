# Fix: KP master preserve + finance hydrate (2026-09-20)

Контекст: ревью GM KP↔LC metrics (Миколиков #298). Гильдия в hold.

## Что сломано

1. History/list upsert с пустой ячейкой «Мастер» обнулял `master_name` / `master_external_id` → GM `Order not found` при живой строке в `orders_cache` (2641151, 2645892).
2. List/history не скрейпят `spares_cost` → `amountCompRub=0` на completed.

## Что сделано (lead-desk)

- `DeskSyncService::masterNameFromPayload` — пустое имя **сохраняет** кэш.
- `masterExternalIdFromPayload` — пустой/отсутствующий id **сохраняет** кэш; сброс id только при смене имени без нового id.
- `hydrateKpFinanceGaps` — после обычного sync дожимает до 6 completed без `parts`/`paid`/`master_external_id` через `fetchOrder`.

## Не закрыто этим патчем

- 16-я заявка vs KP «16» — нужен diff со стороны отчёта KP.
- Полный backfill ЗПЧ по всему сентябрю — идёт порциями на каждом `desk:sync` (лимит 6/город).

## Приёмка

1. History upsert с `master_name: null` не отвязывает мастера (тест `DeskSyncMasterPreserveTest`).
2. После нескольких sync-циклов у #298 в snapshot появляются ненулевые `amountCompRub` там, где в KP есть ЗПЧ.
3. Пинг GM на повторную сверку metrics/orders.
