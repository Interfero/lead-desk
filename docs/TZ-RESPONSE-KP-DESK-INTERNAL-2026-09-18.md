# Desk internal: documents / close / sd для KP (ответ на ТЗ GM 2026-09-18)

Статус реализации: **код в `/var/www/lead-desk` готов** (тот же хост, что prod Desk).  
Публичный `/api/v1/desk/*` не расширяли — гильдия ходит в **Desk internal**, как уже для status.

## Paths (канон)

База prod: `https://lead-control.space`  
Префикс: **`/desk/internal/orders/{id}/…`** (без `/api/v1` — как status с 16.09.2026).

| Метод | Path | Назначение |
|---|---|---|
| POST | `/desk/internal/orders/{id}/status` | уже было (регрессия сохранена; `{id}` теперь ещё и `kp-N`) |
| POST | `/desk/internal/orders/{id}/documents` | multipart `file` + `category` |
| POST | `/desk/internal/orders/{id}/close` | закрытие → КП «Готов» / `completed` |
| POST | `/desk/internal/orders/{id}/sd` | СД → `in_progress_sd` (**в этом релизе**) |
| POST | `/desk/internal/orders/{id}/reveal-office` | квартира KP (для LC/GM auto-reveal; см. TZ-RESPONSE-KP-APARTMENT-GM) |

Имя закрытия: **`close`** (не `review`).

## Канон `{id}`

Принимаются оба:

- `kp-2651619` (как оперирует гильдия)  
- `2651619` (цифры КП)

Внутри нормализуется к цифрам. В ответах `external_id` — цифровой.

## Auth

Тот же Bearer, что для status: **`DESK_INTERNAL_TOKEN`**  
Заголовок: `Authorization: Bearer <token>`  
Отдельный `GM_DESK_INTERNAL_TOKEN` не вводили — на 421 передаёте существующий `DESK_INTERNAL_TOKEN` (тот же, что уже в LC для accept / in-progress).  
**Не коммитить и не светить в чат/git.**

## Категории documents

`receipts` | `contract` | `storage_receipt` | `parts_photos`  
Лимит файла: 20 МБ → HTTP **413** `payload_too_large`.

## Close body

```json
{
  "amountPaidRub": 5500,
  "amountCompRub": 1500,
  "masterComment": "текст отписки мастера"
}
```

Маппинг: `amountPaidRub` → оплачено клиентом; `amountCompRub` → ЗПЧ / комплектующие.  
Отписка мастера (`masterComment` на **close** / **sd**) пишется в КП в **«Комментарий филиала»** (`CustomerRequest[recommendation_comment]`).  
Основное «Комментарий» / описание заявки Desk **не трогает**.  
`comment` на **status** принимается для совместимости, но **не пишется** в КП (служебные «Принято через GM» и т.п. больше не засоряют карточку).  
Статус после успеха close: `raw_status=completed`, `status=closed`.  
Касса/расчёт — тот же путь, что UI Desk close (`Crm2HttpAdapter::closeOrder` + расчётка КП).

Флаги БСО/ЗПЧ, если в кэше пусто: `with_bso` из наличия contract / суммы ≥ 3000; `with_zip` из `amountCompRub > 0`.

## СД

В этом релизе: **да** — `POST …/sd` с `{ "masterComment": "…" }`.

## Коды ошибок

| Ситуация | HTTP | code |
|---|---:|---|
| Успех | 2xx | — |
| Уже финал | 409 | `order_already_final` |
| Нельзя из статуса | 409 | `invalid_status_transition` |
| КП/хаб не пишет | 502 | `source_write_failed` |
| Нет заявки | 404 | `not_found` |
| Не KP | 403 | `forbidden` |
| Нет/битый токен | 401 | `unauthorized` |
| Токен не настроен | 503 | `internal_write_disabled` |
| Файл велик | 413 | `payload_too_large` |
| Бизнес-валидация close | 422 | `validation_error` |

Тело: JSON `{ ok, code, message }` (+ поля статуса при конфликтах).

## curl (подставить TOKEN локально)

```bash
BASE=https://lead-control.space
ID=kp-2651619
TOKEN=***   # DESK_INTERNAL_TOKEN

# A/B — документы
curl -sS -X POST "$BASE/desk/internal/orders/$ID/documents" \
  -H "Authorization: Bearer $TOKEN" -H "Accept: application/json" \
  -F "category=receipts" -F "file=@./tiny.png"

curl -sS -X POST "$BASE/desk/internal/orders/$ID/documents" \
  -H "Authorization: Bearer $TOKEN" -H "Accept: application/json" \
  -F "category=contract" -F "file=@./tiny.jpg"

# C — close
curl -sS -X POST "$BASE/desk/internal/orders/$ID/close" \
  -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" \
  -d '{"amountPaidRub":5500,"amountCompRub":1500,"masterComment":"отписка приёмки"}'

# D — повтор → 409 order_already_final
# E — status без регрессии
curl -sS -X POST "$BASE/desk/internal/orders/$ID/status" \
  -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" \
  -d '{"raw_status":"in_progress"}'
```

## Выкладка

Код лежит в рабочем дереве prod Desk (`/var/www/lead-desk`). PHP подхватывает файлы без отдельной сборки.  
**Дата выкладки кода на диск:** 2026-09-18.  
Приёмка на живой KP (`#2651619` / мастер 300) — по чеклисту §4 ТЗ; токен GM передаётся out-of-band на 421.

## Не в scope

- Публичный list/detail Desk API с полным архивом KP  
- Браузер гильдии → Desk  
- Обязательный прокси documents/review через LC GM API
