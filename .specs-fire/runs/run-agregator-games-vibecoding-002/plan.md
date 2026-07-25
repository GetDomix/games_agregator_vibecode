---
run: run-agregator-games-vibecoding-002
work_item: central-price-refresh
intent: unified-price-watchlist-mvp
mode: validate
checkpoint: plan
checkpoint_state: approved
approved_at: 2026-07-25T15:30:00Z
---

# Implementation Plan: Централизованное обновление цен

## Approach

Работа реализует server-side конвейер вокруг уже созданных канонических таблиц, без новых магазинов и без перехода публичного поиска на хранилище. Laravel scheduler каждую минуту ставит только due source states в database queue. Один unique job обновляет одну пару «игра + источник», применяет нормализованный результат транзакционно и выставляет следующий срок либо source-specific backoff.

Порядок реализации:

1. Добавить retry-метаданные, конфигурацию интервалов, лимитов и batch-size.
2. Ввести контракт источника, нормализованный результат и адаптеры поверх существующих Steam/Plati/GGsel сервисов.
3. Расширить Steam release metadata и безопасно различать валидный пустой результат и ошибку источника.
4. Реализовать `GameRefreshRequestService` и `GamePriceRefreshService`: создание source states, released/announced gating, current/history upsert, stale/failed status и backoff.
5. Реализовать unique queue job, due dispatcher, history prune, named rate limiters и Laravel schedule.
6. Перевести добавление и ручной refresh избранного на queued flow; сохранить формат legacy-ответа, добавив `queued`.
7. Перевести legacy Radar на canonical Steam price; удалить APScheduler-trigger из Python-бота и сделать старый internal endpoint совместимым без внешнего сканирования.
8. Добавить read-only API сохранённых цен, freshness и error state.
9. Написать fake-adapter PostgreSQL tests, затем прогнать PHP, Pint и Python syntax checks.

## Files to Create

| File | Purpose |
|------|---------|
| `backend/database/migrations/2026_07_25_*_add_refresh_retry_state_to_game_source_states.php` | Счётчик неудач для backoff. |
| `backend/app/Contracts/PriceSourceAdapter.php` | Контракт нормализованного source adapter. |
| `backend/app/Data/PriceSourceResult.php` | Immutable результат обновления одного источника. |
| `backend/app/Services/PriceSources/SteamPriceSourceAdapter.php` | Official Steam price and release status adapter. |
| `backend/app/Services/PriceSources/PlatiPriceSourceAdapter.php` | Plati aggregation by offer kind. |
| `backend/app/Services/PriceSources/GgselPriceSourceAdapter.php` | GGsel aggregation by offer kind. |
| `backend/app/Services/PriceSourceRegistry.php` | Выбор adapter по source id. |
| `backend/app/Services/GameRefreshRequestService.php` | Инициализация и ускорение source states. |
| `backend/app/Services/GamePriceRefreshService.php` | Транзакционное применение результата и failure backoff. |
| `backend/app/Jobs/RefreshGameSourceJob.php` | Unique queued refresh job. |
| `backend/app/Console/Commands/DispatchDueGameRefreshesCommand.php` | Due states → queue dispatch. |
| `backend/app/Console/Commands/PruneGamePriceHistoryCommand.php` | Удаление истории старше 90 дней. |
| `backend/app/Http/Controllers/Api/GamePriceController.php` | Read-only canonical prices API. |
| `backend/tests/Feature/CentralPriceRefreshTest.php` | PostgreSQL, Queue fake, release gating and backoff tests. |

## Files to Modify

| File | Changes |
|------|---------|
| `backend/app/Models/GameSourceState.php` | Cast и fillable retry счётчика. |
| `backend/app/Services/SteamService.php` | `coming_soon`, release date и distinguishable refresh failure. |
| `backend/app/Services/AggregatorService.php` | Переиспользовать нормализацию offer groups без изменения текущего HTTP-контракта. |
| `backend/app/Providers/AppServiceProvider.php` | Named rate limiters по источникам. |
| `backend/config/gpa.php` | 3h/24h, source budgets, batch, retry settings. |
| `backend/.env.example` | Документировать новые локальные настройки. |
| `backend/bootstrap/app.php` | Scheduler для dispatcher, prune и read-only Radar evaluation. |
| `backend/app/Http/Controllers/Api/FavoriteController.php` | Link Game, enqueue refresh, убрать sync external aggregate. |
| `backend/app/Services/RadarScanService.php` | Читать canonical prices вместо Steam HTTP. |
| `backend/app/Http/Controllers/Api/TelegramController.php` | Compatibility trigger dispatches due work rather than scanning Steam. |
| `backend/routes/api.php` | Зарегистрировать read-only game prices route. |
| `bot/main.py` | Убрать APScheduler и call общего скана. |
| `bot/config.py` | Убрать radar-trigger setting. |
| `bot/api_client.py` | Убрать internal radar request. |
| `bot/requirements.txt` | Удалить APScheduler dependency. |
| `bot/README.md` | Обновить ownership расписания. |

## Tests

| Test File | Coverage |
|-----------|----------|
| `backend/tests/Feature/CentralPriceRefreshTest.php` | Due dispatch, unique job, announced market gate, release activation, success current/history, error preservation/backoff, manual favorite queue and read-only API. |
| `backend/tests/Feature/CanonicalGamePriceModelTest.php` | Регрессия модели и constraints. |
| `backend/tests/Feature/ApiSmokeTest.php` | Регрессия существующего API. |

## Technical Details

- Dispatcher выполняется каждую минуту, но вызывает внешние источники только через due jobs.
- Интервал успеха: Steam announced 24h; released source 3h. Ошибки: 1/5/15 минут с дальнейшим ограниченным exponential backoff.
- Job идентифицируется `game_id:source`, uses `ShouldBeUnique`, `WithoutOverlapping` and source-specific `RateLimited` middleware.
- Успех удаляет obsolete current rows только того источника; ошибка не изменяет current/history.
- Plati/GGsel не запускаются, пока `Game::isReleased()` false, независимо от due state.
- Источник market обновляется только при существующем source state; выбор пользовательских источников добавит следующий work item.
- Scheduler/queue process definitions в Docker Compose намеренно не добавляются до финального `release-readiness-operations`; этот этап создаёт проверяемый Laravel runtime contract.

## Based on Design Doc

Reference: `.specs-fire/intents/unified-price-watchlist-mvp/work-items/central-price-refresh-design.md`

---
*Checkpoint 2 approved. Implementation is in progress.*
