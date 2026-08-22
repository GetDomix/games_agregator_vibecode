---
run: run-agregator-games-vibecoding-012
work_item: release-readiness-operations
intent: unified-price-watchlist-mvp
generated: 2026-07-26T09:40:00Z
mode: confirm
---

# Implementation Walkthrough: Сквозные тесты и production scheduler

## Summary

Релизный контур Игроскана теперь использует одного владельца расписания: Laravel scheduler ставит canonical price refresh jobs в очередь, worker обновляет серверное хранилище и запускает общую проверку целевых цен, а Telegram-бот остаётся вторым интерфейсом того же аккаунта. Устаревший прямой `radar:scan` удалён. Production deploy больше не запускается от обычного push и требует ручного запуска из `main/master` и approval GitHub environment `production`.

## Architecture

```text
Laravel scheduler
  └─ prices:dispatch-due
       └─ database queue
            └─ RefreshGameSourceJob
                 ├─ Steam / Plati / GGsel adapter
                 ├─ canonical current prices + history
                 ├─ AlertEvaluationService
                 └─ DeliverAlertEventJob → Telegram

Website ─┐
         ├─ Laravel API → one user/favorites/alerts dataset
Bot ─────┘
```

Only the `backend` container runs migrations. `scheduler`, `queue-worker`, frontend and bot wait for a healthy backend. Database queue `retry_after` is 120 seconds, above the 90-second price job timeout.

## Main Changes

- Added `ops:snapshot --hours=24` with per-source states, refreshed-game count, queue depth, failed jobs, alert events and delivery statuses. It runs hourly and writes structured logs without personal data.
- Added structured refresh, dispatch and Telegram delivery logs with IDs, source, status, attempts, duration and event counts; tokens and message payloads are not logged.
- Globally blocked stray Laravel HTTP requests in tests and explicitly faked Steam, Plati, GGsel and Telegram boundaries.
- Added bot API and aiogram/FSM tests; malformed backend responses now become friendly bot errors and image downloads use the injectable test transport.
- Hardened Telegram account merge: conflicts abort atomically, dependent history/identities move to the website account, and duplicate-favorite alert events and deliveries are preserved with collision-free cycles.
- Removed `RadarScanCommand`, `RadarScanService`, `/internal/radar/run`, old radar knobs and the stale Python/SQLite Compose override.
- Added manual production runbook with PostgreSQL backup, smoke checks, monitoring, code rollback and destructive-migration restore.
- Corrected the website Radar copy discovered during browser smoke: MVP alerts use a target price across selected Steam/Plati/GGsel offer types; percentage-drop alerts are not advertised.

## Files Created

| File | Purpose |
|---|---|
| `backend/app/Console/Commands/OperationsSnapshotCommand.php` | Aggregate operational health snapshot. |
| `backend/tests/Feature/ReleaseReadinessOperationsTest.php` | PostgreSQL, source fakes, schedule and snapshot regression tests. |
| `backend/tests/Unit/TelegramNotifyServiceTest.php` | Telegram transport contract and failures. |
| `bot/tests/test_handlers.py` | Isolated aiogram/FSM behavior. |
| `deploy/RELEASE_RUNBOOK.md` | Manual safe-release and restore procedure. |

## Files Removed

| File | Reason |
|---|---|
| `backend/app/Console/Commands/RadarScanCommand.php` | Removed competing legacy scan entry point. |
| `backend/app/Services/RadarScanService.php` | Removed direct Steam-only radar pipeline. |
| `deploy/docker-compose.override.yml` | Removed incompatible Python/SQLite deployment configuration. |

## Verification

| Check | Result |
|---|---|
| Laravel full suite on PostgreSQL | 63/63 passed, 270 assertions |
| Bot suite in bot container | 18/18 passed |
| Frontend lint | Passed with one pre-existing Fast Refresh warning in `brand.tsx` |
| Frontend production build | Passed |
| Changed backend files, Pint | Passed |
| Compose configuration | Passed; all eight expected services present |
| Schedule | Exactly canonical dispatch, history prune and operational snapshot |
| Internal routes | Nine Telegram interface routes; no legacy radar trigger |
| `ops:snapshot` against PostgreSQL | Passed and returned source/queue/event/delivery aggregates |
| Browser smoke | Home, cabinet and Radar screens passed; no Pro UI; active alert scopes visible |
| Stray legacy/Pro source scan | No active `radar:scan`, APScheduler, billing/quota or Pro references in product sources/docs |
| Git diff whitespace check | Passed; only line-ending notices |

## Deviations and Known Limitations

- A full Docker image build was attempted twice. The bot image built, but the shared Laravel build failed while Composer downloaded `doctrine/lexer` from GitHub with `SSL_ERROR_SYSCALL`; the second attempt failed at the same external TLS boundary. Compose parsing and application builds/tests passed, and CI now makes the complete image build a required gate.
- Repository-wide Pint reports five pre-existing formatting-only findings outside this work item. Every backend file touched by this work item passes Pint.
- No production deploy, SSH, real Telegram polling or real store request was performed.

## Release Procedure

Use `deploy/RELEASE_RUNBOOK.md`. Push/PR only runs verification. To release, manually run Pipeline from `main` or `master`, set `deploy_production=true`, approve the protected `production` environment, then complete the documented smoke and monitoring steps.

## Ready for Review

- [x] Acceptance criteria implemented
- [x] PostgreSQL and bot regression suites passing
- [x] Scheduler ownership is singular
- [x] External HTTP is isolated in tests
- [x] Operational diagnostics and release runbook added
- [x] No automatic production deploy

---
*Generated by specs.md FIRE Flow Run run-agregator-games-vibecoding-012*
