---
run: run-agregator-games-vibecoding-012
work_item: release-readiness-operations
intent: unified-price-watchlist-mvp
mode: confirm
checkpoint: plan
approved_at: 2026-07-26
---

# Implementation Plan: Сквозные тесты и production scheduler

## Approach

Закрыть последний release-readiness слой без production deploy: сначала усилить изоляцию и сквозные тесты, затем убрать второй legacy-путь радара, назначить Laravel единственным владельцем расписания, сделать миграцию одновладельческой при старте Compose, добавить структурированные operational snapshots и только после этого обновить CI/runbook. Все проверки выполняются локально или в CI-контуре на PostgreSQL; реальные Steam, Plati, GGsel и Telegram не вызываются.

Текущие незакоммиченные изменения run 011 сохраняются. Этот run не делает commit, push или deploy. В частности, текущий автоматический deploy из `main/master` будет заменён ручным `workflow_dispatch` gate только после утверждения этого плана.

## Files to Create

| File | Purpose |
|------|---------|
| `backend/app/Console/Commands/OperationsSnapshotCommand.php` | Выводить структурированный operational snapshot за период: обработанные игры, freshness/errors по источникам, очередь, созданные alert events и статусы доставок. |
| `backend/tests/Feature/ReleaseReadinessOperationsTest.php` | Зафиксировать PostgreSQL-контур, scheduler ownership, source HTTP fakes, operational snapshot, merge edge cases и delivery replay/deduplication. |
| `backend/tests/Unit/TelegramNotifyServiceTest.php` | Проверить Telegram URL/form contract, отсутствие токена, non-2xx и transport exception через `Http::fake()`. |
| `bot/tests/test_handlers.py` | Изолированно проверить aiogram handlers/FSM: private-chat gate, поиск, выбор игры, scopes, цель, favorites/alerts/rearm/remove и дружелюбные ошибки. |
| `deploy/RELEASE_RUNBOOK.md` | Порядок preflight → backup → migrate/start → smoke → monitoring → rollback/restore без автоматического deploy. |

## Files to Modify

| File | Changes |
|------|---------|
| `backend/tests/TestCase.php` | Запретить случайные внешние HTTP-запросы в Laravel tests через `Http::preventStrayRequests()`. |
| `backend/tests/Feature/AlertEvaluationDeliveryTest.php` | Добавить повторное выполнение уже отправленного event и DB uniqueness assertions. |
| `backend/tests/Feature/TelegramAccountMergeTest.php` | Добавить idempotence/conflict/target-and-scope merge edge cases без потери зависимых данных. |
| `backend/app/Services/GamePriceRefreshService.php` | Возвращать число созданных alert events из refresh/apply, не меняя stored-price поведение. |
| `backend/app/Jobs/RefreshGameSourceJob.php` | Логировать завершение/ошибку refresh с `game_id`, source, duration, freshness, failures и `events_created`. |
| `backend/app/Jobs/DeliverAlertEventJob.php` | Логировать sent/skipped/failed delivery с event/delivery IDs и attempts, не раскрывая токены или полный Telegram payload. |
| `backend/app/Console/Commands/DispatchDueGameRefreshesCommand.php` | Добавить структурированный dispatch summary в stderr/application log. |
| `backend/bootstrap/app.php` | Оставить единый Laravel schedule, добавить периодический `ops:snapshot`; regression test подтверждает отсутствие `radar:scan`. |
| `backend/app/Http/Controllers/Api/TelegramController.php` | Удалить устаревший ручной запуск общего radar scan. |
| `backend/routes/api.php` | Удалить `/internal/radar/run`; bot API и service-token middleware оставить без изменений. |
| `backend/config/gpa.php` | Удалить конфигурацию legacy RadarScanService; source refresh intervals/budgets оставить. |
| `backend/config/queue.php` | Увеличить database `retry_after` выше 90-секундного timeout price job, чтобы снизить риск параллельной повторной обработки. |
| `backend/.env.example` | Удалить legacy radar knobs и документировать безопасный `DB_QUEUE_RETRY_AFTER`. |
| `backend/docker-entrypoint.sh` | Выполнять migrations только при `RUN_MIGRATIONS=true`; scheduler/worker больше не соревнуются за migration lock. |
| `docker-compose.yml` | Назначить backend единственным migration owner, добавить backend/scheduler/worker healthchecks/dependencies, удалить legacy radar env и явно задать безопасный queue retry window. |
| `.github/workflows/pipeline.yml` | Добавить frontend lint, bot unit tests, Compose validation и image build; production deploy сделать только ручным через boolean input и protected `production` environment. |
| `bot/api_client.py` | Оборачивать malformed/non-JSON backend responses в `LaravelApiError`; применять injectable transport и к image requests для полной test isolation. |
| `bot/tests/test_api_client.py` | Покрыть все wrapper paths/payloads, timeout/non-JSON errors и image size/status behavior через `MockTransport`. |
| `README.md` | Удалить инструкцию legacy `radar:scan`, описать Laravel scheduler/queue и ссылку на release runbook. |
| `bot/README.md` | Явно закрепить отсутствие APScheduler и команды запуска изолированных bot tests. |
| `deploy/README.md` | Заменить устаревшие Python/SQLite/pytest/port-8000 инструкции актуальным кратким указателем на Laravel/PostgreSQL runbook. |
| `deploy/docker-compose.override.yml` | Удалить устаревший override с несуществующим `web` и SQLite, который ломает combined Compose validation. |

## Files to Delete

| File | Reason |
|------|--------|
| `backend/app/Console/Commands/RadarScanCommand.php` | Legacy direct radar path больше не должен конкурировать с canonical refresh → alert evaluation → delivery pipeline. |
| `backend/app/Services/RadarScanService.php` | Удалить второй владелец общего скана; бот остаётся только интерфейсом Laravel API. |
| `deploy/docker-compose.override.yml` | Файл относится к старому Python/SQLite сервису `web` и не совместим с текущим Compose. |

## Tests

| Test File / Check | Coverage |
|-------------------|----------|
| `backend/tests/Feature/ReleaseReadinessOperationsTest.php` | PostgreSQL-only guard, explicit Steam/Plati/GGsel fakes, schedule list, no legacy radar owner, operational counts/freshness/errors. |
| `backend/tests/Unit/TelegramNotifyServiceTest.php` | Реальный Laravel HTTP contract Telegram полностью подменён и не выходит в сеть. |
| `backend/tests/Feature/AlertEvaluationDeliveryTest.php` | Повторный sent job не отправляет сообщение повторно; DB constraints блокируют duplicate event/delivery. |
| `backend/tests/Feature/TelegramAccountMergeTest.php` | Merge priority, union scopes, idempotence и конфликт уже связанного Telegram account. |
| `bot/tests/test_handlers.py` | Команды и FSM Telegram без polling и реального Telegram/Laravel. |
| `bot/tests/test_api_client.py` | Все API paths/payloads, malformed responses, timeout и images через `httpx.MockTransport`. |
| Full Laravel suite | Все migrations/features на PostgreSQL `gpa_test`; `Http::preventStrayRequests()` гарантирует отсутствие случайной сети. |
| Frontend lint/build | `npm run lint` и production `npm run build`. |
| Bot suite | `python -m unittest discover -s tests -v` внутри bot image, потому что host Python сейчас отсутствует. |
| Compose/containers | Root config, image build, isolated `db/backend/scheduler/queue-worker` startup, health, `schedule:list`, `ops:snapshot`, dispatch and logs. |
| Browser smoke | Регистрация → stored search → favorite/scopes/target → cabinet на desktop/mobile; без реальных магазинов. |

## Technical Details

1. **Outbound isolation.** Базовый Laravel `TestCase` запрещает stray HTTP. Release tests задают отдельные fakes для Steam appdetails/storesearch, Plati, GGsel и Telegram и проверяют request shape и нормализованные результаты. Bot transport инъецируется во все httpx-вызовы, включая загрузку изображения.
2. **Scheduler ownership.** Единственный автоматический бизнес-процесс: Laravel `prices:dispatch-due`; queue job обновляет canonical prices, а `GamePriceRefreshService` после сохранения вызывает `AlertEvaluationService`. Python bot не содержит APScheduler. Legacy `radar:scan`, internal run route и сервис удаляются, чтобы их нельзя было случайно запустить параллельно.
3. **Migrations.** Только `backend` стартует с `RUN_MIGRATIONS=true`. `scheduler` и `queue-worker` ждут healthy backend и запускаются без migrations. Это исключает три одновременных `php artisan migrate` из общего entrypoint.
4. **Queue safety.** `DB_QUEUE_RETRY_AFTER` устанавливается заметно выше 90-секундного timeout price job. Existing unique/overlap locks сохраняются; тест подтверждает replay-safe Telegram delivery.
5. **Observability.** `ops:snapshot --hours=24` агрегирует без персональных данных: distinct refreshed games, fresh/pending/failed/stale states per source, queue depth/failed jobs, alert events and sent/failed/pending/skipped deliveries. Scheduler пишет snapshot периодически, refresh/delivery jobs — структурированные outcome logs.
6. **CI gates.** Backend PostgreSQL, frontend lint/build, bot tests, Compose validation and Docker image build становятся обязательными. Deploy job больше не срабатывает на обычный push: только manual dispatch с `deploy=true` из `main/master` и GitHub environment `production`.
7. **Runbook and rollback.** Перед migration обязательны `pg_dump` и сохранение текущего revision/image tags. Проверка включает migrations, health, scheduler/worker health, queue depth, snapshot и browser/API smoke. Для destructive migrations восстановление выполняется из backup, а не обещается через `migrate:rollback`.
8. **No deploy in this run.** Мы проверим workflow/commands локально, но не запускаем GitHub deployment, SSH, production migration, Telegram polling или реальные store APIs.

## Audit Findings Incorporated

- Backend уже хорошо покрывает PostgreSQL constraints, `announced`, filters, merge и базовую delivery deduplication; новые тесты сосредоточены на HTTP boundaries, replay/race and merge edges.
- Compose уже имеет отдельные scheduler/worker, но shared entrypoint запускает migrations во всех контейнерах.
- Python APScheduler отсутствует; неоднозначность создаёт оставшийся Laravel legacy radar path.
- Current CI пропускает frontend lint, bot tests и Compose; deploy docs/override относятся к старому стеку.
- Current deploy автоматически запускается на push и не имеет фактического protected environment/rollback gate.

---
*Plan approved at the Confirm checkpoint on 2026-07-26. Implementation completed without production deploy.*
