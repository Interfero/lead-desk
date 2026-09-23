# 2026-09-18 — Desk internal documents / close / sd для KP

## Context

Гильдия (repair-guild.ru) закрывает KP server-to-server. LC GM `documents`/`review` на KP дают 409 `source_action_not_supported`. Status уже идёт через Desk internal.

## Decision

Расширить тот же контур `POST /desk/internal/orders/{id}/…` + `DESK_INTERNAL_TOKEN`:

- `documents` (multipart)
- `close` (JSON amountPaidRub / amountCompRub / masterComment)
- `sd` (JSON masterComment) — в том же релизе
- `{id}`: `kp-N` и `N`

Пути **без** `/api/v1` (как существующий status).

## Consequences

GM подключает роутер на Desk internal; fallback на KP снимается после приёмки A–F.
