---
run: run-agregator-games-vibecoding-011
work_item: free-search-monetization-cleanup
intent: unified-price-watchlist-mvp
mode: validate
checkpoint: plan
approved_at: 2026-07-25T19:49:18Z
---

# Implementation Plan: Бесплатный поиск и удаление Pro

## Approach

Выполнить изменение spec-first: сначала зафиксировать согласованные контракты failing feature-тестами, затем удалить Pro/квоты из схемы, backend API и React-клиента одним согласованным срезом. Старые migrations не меняются; новая migration обновляет существующую PostgreSQL-базу и имеет только структурный rollback. После реализации прогоняются новый тест, полный backend suite, чистая PostgreSQL migration, frontend lint/build, Compose validation, статический поиск остатков и браузерные desktop/mobile сценарии.

## Files to Create

| File | Purpose |
|------|---------|
| `backend/database/migrations/2026_07_25_194100_remove_pro_and_search_quotas.php` | Удалить `daily_search_quotas`, `users.plan` и `users.plan_expires_at`; в `down()` восстановить только прежнюю структуру. |
| `backend/tests/Feature/FreeSearchMonetizationCleanupTest.php` | Спецификационные тесты бесплатного поиска, удалённых endpoints, migration upgrade, auth/favorites/history, ads и technical throttle. |

## Files to Modify

| File | Changes |
|------|---------|
| `backend/app/Http/Controllers/Api/BillingController.php` | Удалить файл целиком вместе с plans, checkout request и promo activation. |
| `backend/app/Models/DailySearchQuota.php` | Удалить файл целиком: дневной счётчик больше не является доменной моделью. |
| `backend/routes/api.php` | Удалить `/quota`, `/plans`, `/billing/request`, `/billing/promo`, `/admin/users/{id}/plan`; сохранить `/search` `30/min` и `/prices` `20/min`. |
| `backend/app/Http/Controllers/Api/PriceController.php` | Удалить `quota()`, `quotaInfo()`, `consumeQuota()`, импорт модели и поле `quota`; не менять stored-price/history/favorite сценарии. |
| `backend/app/Services/StoredPriceSearchService.php` | Удалить остаточное `quota: null` из canonical stored response. |
| `backend/app/Services/AggregatorService.php` | Удалить остаточное `quota: null` из совместимого response shape; ранжирование не менять. |
| `backend/app/Models/User.php` | Удалить plan fillable/cast, `hasActivePro`, `dailySearchLimit`, `planLabel` и plan-поля из `toPublicArray()`. |
| `backend/app/Http/Controllers/Api/AdminController.php` | Удалить зависимость от `DailySearchQuota`, `pro_active`, `searches_today`, `promo_codes`, `recent_users[].plan` и `setUserPlan()`; сохранить admin access/control. |
| `backend/app/Http/Controllers/Api/AdsController.php` | Оставить только `after_results` и `footer`, убрать Pro-текст и `hidden_for_pro`; сохранить маркировку рекламы. |
| `backend/config/gpa.php` | Удалить free/guest/pro daily limits, цены Pro, promo codes и billing email; не менять ads, partner ID и source budgets. |
| `backend/.env.example` | Удалить переменные квот, тарифов, промокодов и billing; реальные секреты не трогать. |
| `docker-compose.yml` | Удалить `FREE_SEARCHES_PER_DAY` и `GUEST_SEARCHES_PER_DAY` из backend environment; scheduler/worker не менять. |
| `README.md` | Удалить раздел Pro, промокод и admin plan endpoint; сохранить актуальную документацию админки и Telegram. |
| `frontend/src/api.ts` | Удалить `plan`, `plan_label`, `plan_expires_at` из типа `User`. |
| `frontend/src/App.tsx` | Удалить Quota/Plans types, state, startup request, Pro navigation/screens/copy, billing/promo calls, admin plan UI; показывать ads всем только после полного result block и в footer. |
| `frontend/src/styles.css` | Удалить plan/quota selectors и mobile overrides; сохранить deal/ad/footer стили. |

## Tests

| Test File | Coverage |
|-----------|----------|
| `backend/tests/Feature/FreeSearchMonetizationCleanupTest.php` | При legacy limits `1` два последовательных guest/auth запроса дают `200`, `quota` отсутствует, пять удалённых endpoints дают `404`. |
| `backend/tests/Feature/FreeSearchMonetizationCleanupTest.php` | Migration upgrade сохраняет пользователя, логин, избранное и историю, но удаляет quota table и plan columns; auth JSON не содержит plan-полей. |
| `backend/tests/Feature/FreeSearchMonetizationCleanupTest.php` | 21-й `/api/prices` запрос с отдельного IP получает `429`, то есть route throttle сохранён. |
| `backend/tests/Feature/FreeSearchMonetizationCleanupTest.php` | Ads API возвращает ровно `after_results`/`footer`, без Pro-флагов; ads/partner config не меняют состав и порядок stored offers. |
| `php artisan test` | Полная регрессия Laravel API, Telegram identity, favorites, history, alerts и price refresh. |
| `php artisan migrate:fresh --env=testing` | Чистая PostgreSQL схема проходит старые migrations, затем новую removal migration. |
| `npm run lint` + `npm run build` | TypeScript/React не содержит удалённых contract branches и собирается штатно. |
| `docker compose config --quiet` | Compose остаётся валидным после удаления env keys. |
| Browser smoke, desktop + mobile | Нет Pro/квот; гость ищет дважды; пользователь входит, ищет, добавляет избранное и открывает кабинет; реклама только после результатов/footer. |

## Technical Details

1. **Сначала тесты.** Новый feature test создаёт canonical game/source prices локально, не вызывает Steam, Plati, GGsel или Telegram. Первым прогоном он должен подтвердить, что текущая реализация нарушает новую спецификацию.
2. **Migration upgrade.** `up()` использует `Schema::hasTable/hasColumn`, сначала удаляет таблицу quota, затем `plan_expires_at` и `plan`. `down()` восстанавливает прежние типы/defaults и индексы, но прямо документирует, что старые значения не восстанавливаются. Миграции `2026_07_22_*` остаются нетронутыми, потому что свежая база должна пройти их в исходном порядке.
3. **Бесплатный API.** `/api/prices` больше не пишет в дневной счётчик и не может вернуть business-limit `429`. Технический `throttle:20,1` остаётся и проверяется отдельно; `/api/search` и фоновые source budgets также не меняются.
4. **Удалённый публичный контракт.** Billing/quota routes исчезают полностью. Auth responses перестают публиковать plan-поля. Frontend и admin overview меняются в том же run, поэтому не остаётся клиента, ожидающего старую схему.
5. **Сохранность аккаунта.** Migration test имитирует существующую схему через `down()`, создаёт legacy-пользователя с избранным и историей, применяет `up()` и повторно проверяет login и оба пользовательских ресурса. Telegram identity/session покрывается полным существующим suite.
6. **Нейтральная реклама.** Backend отдаёт только placements `after_results` и `footer`. Frontend игнорирует старые `header`, `mid`, `inline_results` и рендерит `after_results` после Steam, Plati и GGsel. `showAds` зависит только от `ADS_ENABLED`, не от аккаунта.
7. **Честная выдача.** Код сортировки предложений не изменяется. Regression assertion сравнивает core offer fields/order при переключении ad config и partner ID; допустимо изменение только партнёрского query-параметра ссылки там, где он уже существует.
8. **Очистка без лишнего охвата.** Архивный `legacy/` и старый корневой Python `.env.example` не входят в активный Laravel/React deploy и не меняются. Production `.env` не читается и не редактируется; даже оставшиеся там старые ключи после релиза будут игнорироваться удалённым config.
9. **Финальная проверка.** После автоматических тестов выполняется статический `rg` по активным backend/frontend/docs путям на `KEYSIGNAL`, Pro UI, billing routes, quota/plan fields, затем браузерный smoke. После этого FIRE code review отдельно проверяет migration rollback risk, API consumers и ad placement.

## Based on Design Doc

Reference: `.specs-fire/intents/unified-price-watchlist-mvp/work-items/free-search-monetization-cleanup-design.md`

---
*Plan approved at checkpoint 2: 2026-07-25T19:49:18Z. Execution follows.*
