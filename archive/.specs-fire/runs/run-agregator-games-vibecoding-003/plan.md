---
run: run-agregator-games-vibecoding-003
work_item: stored-price-search
intent: unified-price-watchlist-mvp
mode: validate
checkpoint: plan
checkpoint_state: awaiting_approval
approved_at: null
---

# Implementation Plan: Поиск из серверного хранилища

## Approach

Заменить вызов `AggregatorService::aggregate()` в ценовом endpoint на canonical read-model. Новый сервис преобразует общие game/current-price/state записи в текущую форму `steam`, `plati`, `ggsel`, `warnings`, `deal`, чтобы существующие карточки продолжили работать. Для неизвестного `appid` endpoint создаст placeholder и поставит Steam refresh в очередь, а не будет ждать внешние магазины.

1. Реализовать `StoredPriceSearchService`: local catalog candidates, game lookup, market group mapping, freshness и objective deal calculation.
2. Добавить в request service безопасное создание placeholder и background Steam request.
3. Перевести `/api/search` на local-first поиск с Steam fallback только при пустом local result.
4. Перевести `/api/prices` на canonical read path; сохранить history/favorite flags, не менять квоты в этом work item.
5. Обновить frontend API types и минимальные индикаторы «обновляется»/«данные устарели» без нового интерфейса.
6. Покрыть known, stale/failed, announced, unknown-queued и local discovery тестами; прогнать backend и frontend checks.

## Files to Create

| File | Purpose |
|---|---|
| `backend/app/Services/StoredPriceSearchService.php` | Локальный catalog и canonical-to-legacy card mapping. |
| `backend/tests/Feature/StoredPriceSearchTest.php` | Stored price API, freshness, announced и async miss coverage. |

## Files to Modify

| File | Changes |
|---|---|
| `backend/app/Http/Controllers/Api/PriceController.php` | Remove synchronous aggregate from `/prices`; local-first `/search`. |
| `backend/app/Services/GameRefreshRequestService.php` | Create/link unknown game placeholder and request Steam asynchronously. |
| `frontend/src/App.tsx` | Extend result types and show safe freshness/refreshing state in existing cards. |
| `backend/tests/Feature/ApiSmokeTest.php` | Preserve endpoint regression coverage if needed. |

## Tests

| Test | Coverage |
|---|---|
| Known canonical game | Existing Steam/market rows map to current frontend response with no source mocks. |
| Announced game | Steam returned; Plati/GGsel are empty and marked not applicable. |
| Unknown appid | Queue fake verifies background Steam job; API returns quickly with `refreshing=true`. |
| Failed/stale source | Previous price remains visible with timestamp and safe warning. |
| Search candidates | Local catalog is used first; Steam fallback is only used for an empty catalog. |
| Frontend | Typecheck/lint/build for updated response contract. |

## Boundaries

- No synchronous calls to Plati/GGsel/Steam from `/api/prices`.
- No schema change, new stores, user source preferences, Pro removal or deployment changes.
- Existing rate limits and quota data remain until `free-search-monetization-cleanup`.

## Based on Design Doc

Reference: `.specs-fire/intents/unified-price-watchlist-mvp/work-items/stored-price-search-design.md`

---
*Checkpoint 2 is awaiting approval. No application code has been changed in this run.*
