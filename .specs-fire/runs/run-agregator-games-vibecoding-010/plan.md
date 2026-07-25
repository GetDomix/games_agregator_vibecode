---
run: run-agregator-games-vibecoding-010
work_item: price-refresh-production-recovery
intent: unified-price-watchlist-mvp
mode: validate
checkpoint: plan
approved_at: pending
---

# Implementation Plan: Production scheduler и заполнение цен

## Approach

Оставить HTTP backend без scheduler обязанностей, добавить два независимых Compose процесса с тем же image/env: ровно один Laravel scheduler и один database queue worker. Затем исправить release transition: Steam-refresh released создаёт отсутствующие Plati/GGsel states с immediate due временем; scheduler ставит jobs в существующий ограниченный queue путь. Тесты подменяют источники и проверяют states/jobs, не делают реальных запросов к магазинам.

## Files to Create

| File | Purpose |
|------|---------|
| `backend/tests/Feature/PriceRefreshProductionRecoveryTest.php` | Проверить новые market states после Steam release, announced guard и stored-card refresh state. |

## Files to Modify

| File | Changes |
|------|---------|
| `docker-compose.yml` | Добавить `scheduler` с `php artisan schedule:work` и `queue-worker` с `php artisan queue:work database --queue=prices,default`; оба получают только существующий env_file/config. |
| `backend/app/Services/GamePriceRefreshService.php` | При переходе Steam game в released выполнить `firstOrCreate` для Plati/GGsel, выставить pending/now и не вызвать магазины синхронно. |
| `backend/tests/Feature/CentralPriceRefreshTest.php` | Расширить release transition: states создаются и Due dispatcher ставит jobs; announced остаётся Steam-only. |
| `backend/tests/Feature/StoredPriceSearchTest.php` | Зафиксировать отображение `refreshing` до появления source prices. |
| `.github/workflows/*` | Добавить `docker compose config --quiet` или скорректировать существующую CI проверку, если workflow уже охватывает Compose. |

## Tests

| Test File | Coverage |
|-----------|----------|
| `backend/tests/Feature/PriceRefreshProductionRecoveryTest.php` | Steam release creates market states, announced avoids them, dispatcher queues due sources. |
| `backend/tests/Feature/CentralPriceRefreshTest.php` | Regression for canonical history, release intervals and source dispatch. |
| `backend/tests/Feature/StoredPriceSearchTest.php` | Stored response reflects asynchronous refreshing without external store call. |
| `docker compose config --quiet` | Scheduler/worker commands and shared Compose config are syntactically valid. |

## Technical Details

1. `scheduler` and `queue-worker` reuse the backend image, `env_file`, DB settings, restart policy and DB health dependency. No second scheduler exists in bot.
2. Worker command names the database connection and processes `prices` before `default`; retries/timeouts follow Laravel existing job configuration rather than a new request path.
3. In `GamePriceRefreshService::apply`, only a successful Steam result transitioning from non-released to released creates market states. A game that remains announced neither creates states nor queues marketplaces.
4. Market state creation happens in the existing transaction; the central scheduler discovers `next_refresh_at <= now()` and dispatches jobs later. This preserves the architecture: user requests only request refresh, never execute store calls.
5. The release acceptance check uses faked queues/adapters and PostgreSQL. Production verification after user-approved deploy checks Compose service state plus a read-only stored price endpoint/freshness timestamp; no credentials are printed.

## Based on Design Doc

Reference: `.specs-fire/intents/unified-price-watchlist-mvp/work-items/price-refresh-production-recovery-design.md`

---
*Plan awaiting approval at checkpoint 2. Implementation does not start before approval.*
