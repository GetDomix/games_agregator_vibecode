---
work_item: stored-price-search
intent: unified-price-watchlist-mvp
created: 2026-07-25T16:00:00Z
mode: validate
checkpoint_1: approved
---

# Design: Поиск из серверного хранилища

## Summary

Публичный поиск сохранит текущие карточки, но их цены будут собираться исключительно из канонических таблиц. Локальный каталог станет основным путём, а неизвестная игра создастся как placeholder и получит фоновое Steam-обновление, не блокируя HTTP-ответ.

## Scope

**In Scope:**
- Read-model, который преобразует `games`, `current_game_prices` и `game_source_states` в существующий контракт результатов.
- Локальный поиск игр с ранжированием exact/prefix/contains.
- Безопасный controlled Steam discovery только при отсутствии локального кандидата.
- Placeholder + queued refresh для неизвестного `appid`.
- Freshness, status и safe source errors в ответе.
- `coming_soon` Steam-only карточка.
- Технический rate limit для бесплатного поиска.

**Out of Scope:**
- Пользовательские настройки площадок и видов предложений.
- Изменение центрального refresh pipeline, источников или Docker worker.
- Удаление Pro, платежей и поисковых квот; это отдельный work item.
- Новый дизайн фронтенда; адаптируется существующий контракт карточек.

## Key Decisions

| Decision | Choice | Rationale |
|---|---|---|
| Основной источник результатов | PostgreSQL canonical tables | Запрос цены не должен вызывать магазины. |
| Контракт фронтенда | Сохранить `PriceResponse` и market `by_kind` | Минимизирует риск поломки существующих карточек. |
| Локальный поиск | exact → prefix → contains, лимитируемый | Локальный каталог быстрый и не расходует лимит магазина. |
| Неизвестный appid | Создать `Game` из `appid` и query, enqueue Steam, ответ `refreshing` | Пользователь не ждёт сеть и понимает состояние. |
| Steam discovery | Только `/search` при нулевом local result | Контролируемый единственный внешний запрос для поиска нового appid. |
| Announced | Возвращать Steam и пустые market blocks | Нельзя показывать или искать предложения до релиза. |
| Freshness | `last_success_at`, `next_refresh_at`, status + safe warning | Цена и её давность видны без раскрытия raw errors. |
| Ранжирование | Цена, затем sales; виды не смешиваются | Соответствует честному списку без рекламного влияния. |

## Data Models Affected

### Creates
- **StoredPriceSearchService**: read-model для карточек и local catalog поиска.
- **StoredPriceSearchResult**: DTO/array contract для `PriceController`.

### Modifies
- **PriceController**: `/search` local-first, `/prices` stored-first and queue-on-miss.
- **GameRefreshRequestService**: публичный метод зарегистрировать unknown game без внешнего HTTP.
- **Frontend API types**: freshness/refreshing fields, если они нужны для отображения существующих карточек.

## Technical Approach

### Architecture

```text
GET /search?q
  -> StoredPriceSearchService local catalog
  -> no local candidates only: Steam discovery

GET /prices?q&appid
  -> StoredPriceSearchService (Game + current prices + source states)
  -> known: legacy-shaped PriceResponse + freshness
  -> unknown: Game placeholder -> GameRefreshRequestService -> queue
              -> PriceResponse(refreshing=true, no external HTTP)
```

### API Changes

- `GET /api/search?q=` — local candidates first; `meta.discovery_used` reports controlled Steam fallback.
- `GET /api/prices?q=&appid=` — same top-level cards/markets, adds `freshness` and `refreshing`; no sync aggregation.

### Database Changes

No schema change. Existing canonical tables are the search read model.

## Affected Files

| File | Action | Purpose |
|---|---|---|
| `backend/app/Services/StoredPriceSearchService.php` | Create | Catalog query and response mapping. |
| `backend/app/Http/Controllers/Api/PriceController.php` | Modify | Replace synchronous aggregation with stored search. |
| `backend/app/Services/GameRefreshRequestService.php` | Modify | Queue unknown placeholder safely. |
| `frontend/src/App.tsx` | Modify | Show freshness/refreshing without replacing cards. |
| `backend/tests/Feature/StoredPriceSearchTest.php` | Create | Stored response, unknown queue, announced and no-sync tests. |
| `backend/tests/Feature/ApiSmokeTest.php` | Modify | Regression for existing endpoints. |

## Security Considerations

- **Untrusted query**: keep existing length/empty validation and use parameterized Eloquent queries.
- **Source errors**: expose generic status only; never leak `last_error`.
- **Abuse**: retain route throttle; discovery runs only after an empty local result.

## Integration Points

| System | Type | Purpose |
|---|---|---|
| PostgreSQL | Read model | Canonical game, price and freshness data. |
| Laravel queue | Async handoff | Initial fill of a new placeholder. |
| Steam Store API | Controlled discovery | Candidate fallback only, not price aggregation. |
| React cards | Compatible API consumer | Existing offers and market grouping stay intact. |

## Risks & Mitigations

| Risk | Impact | Mitigation |
|---|---|---|
| Catalog misses a game | Medium | Explicit refreshing response and one Steam discovery path. |
| Stale price mistaken for live | High | Show timestamp/status warning in response and UI. |
| Legacy frontend expects fields | High | Map canonical groups back to current contract; test exact shape. |
| Public search still has quota logic | Medium | Keep it unchanged here; remove it only in dedicated monetization work item. |
| Marketplace shows before release | High | Gate market blocks by `release_status`. |

## Implementation Checklist

- [ ] Implement local catalog query and canonical-to-card mapping.
- [ ] Adapt `/search` to local-first controlled discovery.
- [ ] Adapt `/prices` to stored result and unknown placeholder queue.
- [ ] Add freshness, refreshing and safe warning fields.
- [ ] Update existing UI types and status copy only.
- [ ] Test known, stale, announced and unknown game paths without external HTTP.
- [ ] Run backend suite and frontend type/build checks.

---
*Generated by specs.md - FIRE Flow | Checkpoint 1 approved: 2026-07-25T16:00:00Z*
