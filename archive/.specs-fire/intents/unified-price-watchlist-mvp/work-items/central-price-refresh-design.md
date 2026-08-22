---
work_item: central-price-refresh
intent: unified-price-watchlist-mvp
created: 2026-07-25T15:24:40Z
mode: validate
checkpoint_1: approved
---

# Design: Централизованное обновление цен

## Summary

Laravel станет единственным владельцем обновления цен: минутный диспетчер ставит в очередь только просроченные источники, а уникальные jobs обновляют общие цены игр. Выпущенные игры получают трёхчасовой цикл, а анонсированные — Steam раз в сутки без запросов к Plati и GGsel.

## Scope

**In Scope:**
- Laravel scheduler, queue jobs, retry/backoff и source budgets.
- Адаптеры Steam, Plati и GGsel, пригодные для подмены в тестах.
- Обновление `games`, `game_source_states`, `current_game_prices` и истории.
- Фоновое первичное заполнение при добавлении игры в избранное.
- Перевод ручного refresh избранного и старого Radar на сохранённые цены.
- Удаление APScheduler-вызова общего сканера из Telegram-бота.
- Read-only API текущих цен, свежести и ошибок.
- 90-дневная очистка истории.

**Out of Scope:**
- Перевод публичного поиска на хранилище: это `stored-price-search`.
- Пользовательские галочки источников/видов и правила спроса: это `cross-source-alert-settings`.
- Новый жизненный цикл алертов и Telegram-доставка: это `alert-evaluation-delivery`.
- Production Docker services для постоянных `schedule:work` и `queue:work`: это `release-readiness-operations`.
- Новые источники, обход ограничений, платежи или ранжирование по рекламе.

## Key Decisions

| Decision | Choice | Rationale |
|----------|--------|-----------|
| Частота диспетчера | Каждую минуту, без внешних запросов | Позволяет быстро подобрать просроченные состояния, не нарушая трёхчасовой интервал источников. |
| Частота released | Каждые 3 часа на востребованный источник | Даёт максимум восемь циклов в сутки при общих данных, а не на пользователя. |
| Частота announced | Steam раз в 24 часа | Для анонса маркетплейс-поиск не нужен; Steam остаётся источником факта релиза. |
| Единица работы | Уникальный job `game_id + source` | Исключает повторный запрос одной игры на источник. |
| Защита параллельности | `ShouldBeUnique`, `WithoutOverlapping`, scheduler lock | Защищает и очередь, и несколько scheduler-процессов. |
| Ограничение нагрузки | Named Laravel rate limiters по источнику | Бюджеты Steam 30, Plati 10, GGsel 15 игр/мин настраиваются без изменения кода. |
| Ошибки | Старая успешная цена сохраняется; backoff 1/5/15 минут и далее | Ошибка сети не должна превращать корректную цену в пустую или нулевую. |
| Успешный пустой ответ | Удалить отсутствующие актуальные предложения этого источника | Отсутствие предложения после валидного ответа отличается от ошибки и не должно показывать устаревший лот. |
| Выбор источников | `game_source_states` временно выражает агрегированный спрос | До пользовательских настроек legacy-игры создают только Steam-состояние; следующий work item добавит market спрос. |
| Telegram | Бот не планирует общую работу | Telegram остаётся интерфейсом; Laravel управляет общими ценами и расписанием. |

## Data Models Affected

### Creates
- **PriceSourceAdapter**: единый контракт нормализованного ответа источника.
- **PriceSourceResult**: метаданные игры, release-сигнал и агрегированные предложения одного источника.
- **RefreshGameSourceJob**: уникальная асинхронная работа для игры и источника.

### Modifies
- **GameSourceState**: счётчик последовательных ошибок для backoff.
- **Game**: статус и дата релиза обновляются Steam-адаптером.
- **Favorite**: новая запись связывается с `Game` и ставит фоновую Steam-проверку.
- **SteamService**: дополнительно передаёт `coming_soon` и дату релиза, отличая неудачу источника от валидного отсутствия предложения.
- **RadarScanService**: читает сохранённую Steam-цену вместо внешнего Steam-запроса.

## Technical Approach

### Architecture

```text
Favorite / future stored search
            |
            v
 GameRefreshRequestService
            |
            v
 game_source_states (next_refresh_at)
            |
            v
 prices:dispatch-due -- every minute
            |
            v
 RefreshGameSourceJob (game + source, unique)
            |
   +--------+---------+
   |        |         |
 Steam    Plati     GGsel adapters
   |        |         |
   +--------+---------+
            v
 transaction: Game + state + current prices + observations
            |
            +--> read-only prices API / stored Radar evaluation
```

### API Changes

- `GET /api/games/{appid}/prices` — только сохранённые цены, freshness, error и `next_refresh_at`; без обращения к внешним источникам.
- `POST /api/me/favorites/refresh` — сохраняет существующую форму ответа, но помечает игры к обновлению и возвращает `queued=true` вместо синхронного вызова источников.
- `/api/internal/radar/run` временно сохраняется для старого бота, но только инициирует централизованный dispatch и не запускает per-favorite Steam scan.

### Database Changes

```sql
game_source_states ADD consecutive_failures integer NOT NULL DEFAULT 0;

-- state = fresh after a valid source response
-- state = failed after a transport/API error; previous current_game_prices stay intact
-- next_refresh_at = now + 3h (released), now + 24h (announced Steam),
--                   or exponential backoff after failure
```

### Source Result Rules

- Steam: одна строка `steam/official`; `coming_soon` обновляет `announced`, а переход к `released` активирует существующие market-состояния.
- Plati/GGsel: существующие сервисы и relevance filter сохраняются; данные агрегируются отдельно по каждому виду предложения.
- Успех сохраняет актуальный срез и одно наблюдение на источник/вид; ошибка не удаляет последние данные.
- Наблюдения старше `GamePriceObservation::RETENTION_DAYS` удаляет ежедневная команда.

## Affected Files

| File | Action | Purpose |
|------|--------|---------|
| `backend/database/migrations/*_add_refresh_retry_state.php` | Create | Добавить счётчик неудач. |
| `backend/app/Contracts/PriceSourceAdapter.php` | Create | Контракт адаптера. |
| `backend/app/Data/PriceSourceResult.php` | Create | Нормализованный результат одного источника. |
| `backend/app/Services/PriceSources/*` | Create | Steam, Plati и GGsel adapters. |
| `backend/app/Services/GamePriceRefreshService.php` | Create | Транзакционное применение результата и backoff. |
| `backend/app/Services/GameRefreshRequestService.php` | Create | Создание/активация source states без HTTP ожидания. |
| `backend/app/Jobs/RefreshGameSourceJob.php` | Create | Уникальная queued refresh работа. |
| `backend/app/Console/Commands/DispatchDueGameRefreshesCommand.php` | Create | Поиск due состояний и dispatch. |
| `backend/app/Console/Commands/PruneGamePriceHistoryCommand.php` | Create | Ежедневная очистка истории. |
| `backend/app/Http/Controllers/Api/GamePriceController.php` | Create | Read-only API сохранённых цен. |
| `backend/app/Providers/AppServiceProvider.php` | Modify | Named rate limiters. |
| `backend/bootstrap/app.php` | Modify | Новое Laravel schedule вместо bot-driven общего скана. |
| `backend/config/gpa.php`, `backend/.env.example` | Modify | Интервалы, budgets, batch и backoff. |
| `backend/app/Services/SteamService.php` | Modify | Сигналы релиза и различение ошибок. |
| `backend/app/Http/Controllers/Api/FavoriteController.php` | Modify | Связать Game, поставить фоновый refresh, убрать sync aggregate. |
| `backend/app/Services/RadarScanService.php`, `backend/app/Http/Controllers/Api/TelegramController.php` | Modify | Убрать внешние Steam-запросы и bot-triggered scan. |
| `bot/main.py`, `bot/config.py`, `bot/api_client.py`, `bot/requirements.txt` | Modify | Удалить APScheduler и endpoint общего скана. |
| `backend/tests/Feature/CentralPriceRefreshTest.php` | Create | Queue, release gating, failures, API и history tests. |

## Security Considerations

- **Публичный API**: только read-only access к сохранённым данным; никаких токенов или raw source errors.
- **Источник URL/ошибок**: ошибки сокращаются и логируются сервером; пользователю отдаётся безопасный статус.
- **Дублирование вызовов**: cache locks используют серверный cache store, не пользовательские данные.

## Integration Points

| System | Type | Purpose |
|--------|------|---------|
| PostgreSQL database queue/cache | Job queue and locks | Асинхронность и unique job. |
| Laravel scheduler | Command schedule | Регулярный dispatch и pruning. |
| Steam/Plati/GGsel | Existing HTTP services | Только существующие официально используемые пути и timeout/retry. |
| Existing favorites/Radar | Compatibility | Убираем пер-пользовательские внешние запросы без поломки endpoint. |
| Telegram bot | Removal of scheduler | Бот не становится вторым владельцем расписания. |

## Risks & Mitigations

| Risk | Impact | Mitigation |
|------|--------|------------|
| Нет worker/scheduler в текущем production Compose | High | Код и расписание реализуются сейчас; постоянные Docker-процессы добавляет release-readiness work item. |
| API rate limit магазина | High | Independent budgets, retries, source-specific backoff и отсутствие запросов для announced marketplaces. |
| Дубли jobs | High | Unique job, queue middleware и command overlap lock. |
| Ошибка Steam очищает цену | High | Неуспех не меняет `current_game_prices`; удаление возможно только после валидного пустого ответа. |
| Нерелевантные market offers | Medium | Использовать существующий `OfferRelevance` и classification без смены ранжирования. |
| Очередь не успевает за 3 часами | Medium | Configurable batch/budgets, `next_refresh_at`, наблюдаемый stale status и будущая production readiness. |
| Старый бот вызывает старый endpoint | Medium | Совместимый endpoint больше не вызывает магазины; новый бот удаляет scheduler. |

## Implementation Checklist

- [ ] Добавить retry счётчик и config/env параметры.
- [ ] Реализовать адаптерный контракт и нормализованные Steam/Plati/GGsel результаты.
- [ ] Расширить Steam release metadata без поломки существующего поиска.
- [ ] Реализовать транзакционное обновление current/history/state и exponential backoff.
- [ ] Реализовать unique job, due dispatcher и history prune command.
- [ ] Зарегистрировать rate limiters и Laravel schedule.
- [ ] Перевести favorite store/manual refresh и legacy Radar на общие сохранённые цены.
- [ ] Удалить APScheduler-ownership из Python-бота и сохранить безопасную совместимость internal endpoint.
- [ ] Добавить read-only API freshness/errors.
- [ ] Добавить fake-adapter и PostgreSQL integration tests без реальных источников.

---
*Generated by specs.md - fabriqa.ai FIRE Flow | Checkpoint 1 approved: 2026-07-25T15:24:40Z*
