# KP квартира в GM API (ответ на ТЗ GM 2026-09-18)

Статус: **вариант A реализован** в lead-desk + lead-control.

## 6. Ответ Стёпы

| Вопрос | Ответ |
|---|---|
| Вариант A / B / C / свой | **A** |
| Таймер окна для GM | **30 мин** как Desk (`call_at − 30`), **плюс** статусы `on_way` \| `in_progress` \| `in_progress_sd` (раньше окна, если мастер уже в визите) |
| Поле | Вшиваем в **`address` / `addressFull`** суффиксом (`…, кв / офис: 12`). Отдельное `apartment` не вводим — кабинет уже парсит «кв.» |
| Телефон KP в GM | **Дыра синка, не политика.** GM отдаёт `clientPhone` из `orders_cache.phone` as-is. `null` = в кэше Desk телефона нет (HTML scrape `#customerInfo` часто пустой). Не маскируем специально. Отдельный фикс phone-sync — не в этом релизе. |
| Дата выкладки на prod | **2026-09-19** (после smoke на живом KP ≠ completed) |

## Контракт (A)

`GET /api/v1/gm/orders` и detail для `source=kp_lead`:

1. **До окна** (более 30 мин до `call_at` и статус ещё не визит) — улица/дом, как сейчас.
2. **В окне / в визите**:
   - если в кэше Desk есть `address_office` → суффикс в `address` / `addressFull`;
   - **detail** / ответ после accept|in-progress: если кэша нет → LC один раз зовёт Desk  
     `POST /desk/internal/orders/{id}/reveal-office` → Desk пишет `address_office` → GM отдаёт суффикс;
   - **list**: только кэш (без массового unlock в КП).
3. Повторный reveal **не** долбит КП: Desk отвечает из кэша (`from_cache: true`).

Побочный эффект unlock кв в КП — **норма** внутри окна (как у кнопки Desk).

## Desk internal (для LC, не для гильдии)

| Метод | Path | Auth |
|---|---|---|
| POST | `/desk/internal/orders/{id}/reveal-office` | Bearer `DESK_INTERNAL_TOKEN` |

`{id}`: `2651619` или `kp-2651619`.

Ответ успех: `{ ok, external_id, text, from_cache }`.  
Вне окна: `403` `reveal_not_allowed`.

Гильдия **не** ходит в Desk — только в GM API.

## Не делаем

- Не подмешиваем кв. в обычный desk-sync / list до окна.
- Не требуем от мастера Единое окно.
- Не вводим отдельное поле `apartment` в этом релизе.

## Файлы

**lead-desk:** `DeskAddressOfficeRevealService`, `DeskAddressOffice::canRevealForMaster`, internal `reveal-office`.  
**lead-control:** `HubOrderOfficeService`, `HubOrderPresenter` (merge), `OrderAddressReveal::canRevealForMaster`.

## Smoke

1. Взять живой KP с будущим `call_at` (>30 мин), статус не визит → GM list без «кв».
2. Перевести в `on_way` / `in_progress` (или дождаться окна) → GM list/detail с «кв» / «кв / офис».
3. Повторный GET — без повторного unlock в КП (Desk `from_cache`).
