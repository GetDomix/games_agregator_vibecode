---
run: run-agregator-games-vibecoding-002
work_item: central-price-refresh
intent: unified-price-watchlist-mvp
generated: 2026-07-25T15:44:00Z
mode: validate
---

# Implementation Walkthrough: Централизованное обновление цен

## Summary

Laravel теперь владеет обновлением общей цены игры: пользовательское действие только ставит работу в очередь. Steam, Plati и GGsel получают нормализованные адаптеры; успешные ответы атомарно обновляют current price и history, а ошибка сохраняет последний корректный срез.

## Structure Overview

```text
Favorite -> GameRefreshRequestService -> game_source_states -> queue job
Scheduler -> DueGameRefreshDispatcher --------------------------^
RefreshGameSourceJob -> source adapter -> GamePriceRefreshService
                                      -> games/current/history/state
Read-only API and RadarScanService <- stored canonical prices
```

## Architecture

### Pattern Used

Application service + adapter pattern: adapters isolate existing HTTP services, while application services own scheduling state and transactional persistence.

## Files Changed

### Created

| File | Purpose |
|---|---|
| `backend/database/migrations/2026_07_25_153100_add_refresh_retry_state_to_game_source_states.php` | Failure counter for retries. |
| `backend/app/Contracts/PriceSourceAdapter.php` | Source adapter contract. |
| `backend/app/Data/PriceSourceResult.php` | Immutable normalized snapshot. |
| `backend/app/Services/PriceSources/*` | Steam, Plati and GGsel adapters. |
| `backend/app/Services/PriceSourceRegistry.php` | Maps source id to adapter. |
| `backend/app/Services/GameRefreshRequestService.php` | Links favorites and queues first fill. |
| `backend/app/Services/GamePriceRefreshService.php` | Applies source results and backoff transactionally. |
| `backend/app/Services/DueGameRefreshDispatcher.php` | Finds due states and queues jobs. |
| `backend/app/Jobs/RefreshGameSourceJob.php` | Unique one-game/one-source queued work. |
| `backend/app/Console/Commands/*` | Due dispatch and history pruning commands. |
| `backend/app/Http/Controllers/Api/GamePriceController.php` | Read-only stored price endpoint. |
| `backend/tests/Feature/CentralPriceRefreshTest.php` | End-to-end price-refresh coverage. |

### Modified

| File | Changes |
|---|---|
| `GameSourceState`, `SteamService`, `AppServiceProvider` | Retry metadata, release data and rate limits. |
| `gpa.php`, `.env.example`, `bootstrap/app.php` | Intervals, budgets and schedules. |
| `FavoriteController`, `RadarScanService`, `TelegramController`, `routes/api.php` | Queued user flow, canonical Radar and prices API. |
| `bot/*` | Removed APScheduler ownership and obsolete scan call. |

## Domain Model

| Entity | Properties | Business rules |
|---|---|---|
| `GameSourceState` | source, status, next refresh, failure count | Announced games invoke Steam only; failures back off. |
| `PriceSourceResult` | metadata and offer groups | Represents one valid source snapshot only. |

## Key Implementation Details

### 1. Refresh rules

The minute dispatcher performs no external HTTP itself. Released games refresh each source every 3 hours; announced games refresh Steam every 24 hours and marketplace states are deferred. A Steam transition to released immediately makes existing marketplace states due.

### 2. Failure safety

Only a valid source result removes obsolete current offers. Exceptions leave current prices and observations untouched, mark the state failed and use 1/5/15/30/60-minute backoff.

### 3. Compatibility

The existing favorite refresh endpoint keeps its response shape but returns `queued=true`. The legacy Radar now evaluates stored Steam data. The old internal bot endpoint only dispatches due jobs; the Python bot no longer runs a scheduler.

## Security Considerations

| Concern | Approach |
|---|---|
| Source errors | Public API exposes only `has_error`, never raw source errors. |
| Duplicate/load spikes | Scheduler lock, unique job, overlap lock and per-source rate limits. |
| Public input | Existing favorite validation remains at API boundary. |

## Performance Considerations

| Requirement | Implementation |
|---|---|
| Avoid per-user store calls | Shared game/source states and one unique queued job. |
| Respect source capacity | Independent configurable per-minute budgets. |
| Bound storage | Daily cleanup after 90 days. |

## Decisions Made

| Decision | Choice | Rationale |
|---|---|---|
| Refresh ownership | Laravel scheduler/queue | Bot and site are interfaces over shared data. |
| Announced games | Steam daily only | No marketplace query before a Steam release signal. |
| Legacy scope | Steam state only | Per-source user preferences are deliberately deferred to the next work item. |

## Deviations from Plan

None. `AggregatorService` was intentionally left unchanged because extracting its private aggregation would be an unrelated refactor; the canonical adapters aggregate marketplace offers independently.

## Dependencies Added

| Package | Why Needed |
|---|---|
| (none) | Existing Laravel queue/cache and bot dependencies were sufficient; APScheduler was removed. |

## How to Verify

1. **Run backend tests**
   ```powershell
   $env:DB_USERNAME = 'gpa_test_runner'; $env:DB_PASSWORD = 'gpa_test_runner'; $env:DB_DATABASE = 'gpa_test'; php artisan test
   ```
   Expected: 19 passing tests.

2. **Inspect scheduler**
   ```bash
   php artisan schedule:list
   ```
   Expected: `prices:dispatch-due` every minute and `prices:prune-history` daily.

3. **Queue a game through the API**
   ```text
   POST /api/me/favorites -> receives 201/200 promptly; GET /api/games/{appid}/prices reads stored data only.
   ```
   Expected: no synchronous external request from the favorite endpoint.

## Test Coverage

- Tests added: 5 test methods, 22 assertions.
- Coverage: not instrumented.
- Status: passing (full suite: 19 tests, 71 assertions).

## Ready for Review

- [x] All acceptance criteria met
- [x] Tests passing
- [x] No critical issues
- [x] Documentation updated
- [x] Developer notes captured

## Developer Notes

Production still needs a persistent `schedule:work` and queue worker; that deployment change is explicitly deferred to `release-readiness-operations`. Python validation needs an installed local interpreter.

---
*Generated by specs.md - FIRE Flow Run run-agregator-games-vibecoding-002*
