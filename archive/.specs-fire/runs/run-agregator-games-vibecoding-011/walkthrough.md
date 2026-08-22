---
run: run-agregator-games-vibecoding-011
work_item: free-search-monetization-cleanup
intent: unified-price-watchlist-mvp
generated: 2026-07-26T08:57:54.900Z
mode: validate
---

# Implementation Walkthrough: Бесплатный поиск и удаление Pro

## Summary

Игроскан больше не содержит продуктовой модели Pro, подписок, ручных платежных заявок, промокодов или дневных бизнес-квот. Поиск одинаково бесплатен для гостей и авторизованных пользователей, при этом route-level throttling остаётся технической защитой. Реклама сохранена только после полного списка предложений и в подвале и не участвует в формировании выдачи.

## Structure Overview

React больше не запрашивает и не отображает планы/квоты. Laravel routes и controllers не предоставляют billing/quota контракты, а stored-price services возвращают только данные поиска и аккаунта. PostgreSQL обновляется отдельной guarded migration, которая удаляет устаревшие поля и таблицу, не меняя историю старых migrations.

## Architecture

### Pattern Used

Сохранена существующая связка React presentation layer → Laravel API/application layer → Eloquent/PostgreSQL storage. Монетизация удалена вертикальным срезом из всех трёх уровней, поэтому скрытых контрактов между UI, API и схемой не остаётся.

### Layer Structure

```text
React search / cabinet
        |
        v
Laravel routes + technical throttles
        |
        v
Stored-price services + shared account data
        |
        v
PostgreSQL games, prices, favorites, history, alerts

Neutral ads: after complete results + footer only
```

## Files Changed

### Created

| File | Purpose |
|------|---------|
| `backend/database/migrations/2026_07_25_194100_remove_pro_and_search_quotas.php` | Remove the legacy quota table and user plan columns; restore schema only on rollback. |
| `backend/tests/Feature/FreeSearchMonetizationCleanupTest.php` | Lock the approved free-search, migration, ads and throttling contracts. |

### Modified

| File | Changes |
|------|---------|
| `README.md` | Document free search and the current admin API. |
| `backend/.env.example` | Remove obsolete Pro, billing, promo and quota variables. |
| `backend/app/Http/Controllers/Api/AdminController.php` | Remove plan/quota metrics and plan mutation while retaining admin role management. |
| `backend/app/Http/Controllers/Api/AdsController.php` | Return only neutral after-results and footer placements. |
| `backend/app/Http/Controllers/Api/BillingController.php` | Deleted obsolete plans, payment-request and promo implementation. |
| `backend/app/Http/Controllers/Api/PriceController.php` | Remove daily quota reads/writes and the quota response field. |
| `backend/app/Models/DailySearchQuota.php` | Deleted obsolete daily quota model. |
| `backend/app/Models/User.php` | Remove plan fields, plan behavior and subscription data from the public account payload. |
| `backend/app/Services/AggregatorService.php` | Remove legacy quota response field. |
| `backend/app/Services/StoredPriceSearchService.php` | Remove legacy quota response field without changing offer ordering. |
| `backend/config/gpa.php` | Remove monetization and daily quota configuration. |
| `backend/routes/api.php` | Remove quota/billing/promo/admin-plan routes and retain technical search throttles. |
| `docker-compose.yml` | Remove obsolete daily quota environment keys. |
| `frontend/src/App.tsx` | Remove all Pro/quota screens and branches; render ads after results and within the footer. |
| `frontend/src/api.ts` | Remove plan fields from the shared user type. |
| `frontend/src/styles.css` | Remove plan/quota styling and use three equal mobile navigation columns. |

## Key Implementation Details

### 1. Search contract

`/api/prices` no longer consults or increments a daily counter and no longer returns `quota`. Existing stored-price lookup, history and favorite enrichment are unchanged. `/api/search` remains limited to 30 requests/minute and `/api/prices` to 20 requests/minute.

### 2. Removed public surface

Quota, plans, checkout request, promo activation and admin-plan mutation routes now resolve to `404`. Auth and admin payloads no longer expose plan-related fields or statistics.

### 3. Safe schema evolution

The new migration checks table and column existence before removal, so it works for current and clean databases. Its rollback deliberately restores only the former structure because deleted plan/quota values cannot be reconstructed.

### 4. Neutral advertising

The ads API exposes exactly `after_results` and `footer`. The frontend shows the same placements for every account. Regression tests compare Steam, Plati and GGsel data while ad and partner settings change, confirming that offer composition and order remain stable.

### 5. Responsive UI

Desktop shows the complete result stack before the ad. Mobile keeps the existing Plati/GGsel tab switcher, uses a three-button bottom navigation after Pro removal, and places the footer ad in the semantic footer.

## Security Considerations

| Concern | Approach |
|---------|----------|
| Abuse after removing business quotas | Retained Laravel route throttles for both search endpoints. |
| Account data after dropping columns | Migration regression verifies login, favorites and history; full suite covers shared Telegram identity. |
| Production migration safety | Guarded table/column checks; old applied migrations are untouched. |
| Hidden monetization contracts | Removed routes, public fields, UI consumers, env/config keys and documentation together. |

## Performance Considerations

| Requirement | Implementation |
|-------------|----------------|
| Free search must not trigger external stores per click | Existing stored-price service remains the source for user requests. |
| Removing quotas must not remove traffic protection | Technical per-minute throttles remain active. |
| Ads must not alter ranking work | Ads are fetched/rendered separately from stored offers. |

## Decisions Made

| Decision | Choice | Rationale |
|----------|--------|-----------|
| Search access | Free for guests and users, with technical throttling only. | Daily business limits and subscription upsell are no longer part of the product. |
| Legacy schema | New guarded migration with structure-only rollback. | Preserve migration history and state the irreversible data boundary honestly. |
| Advertising | Only after-results and footer placements for every account. | Keep monetization visible but non-blocking and independent from ranking. |

## Deviations from Plan

No product-scope deviations. The planned Docker image rebuild was unavailable during an earlier check because Docker Hub TLS timed out; Compose validation, clean PostgreSQL migration and desktop/mobile browser verification were completed with local Laravel/Vite processes instead.

## Dependencies Added

| Package | Why Needed |
|---------|------------|
| (none) | The implementation uses existing Laravel, React and test tooling. |

## How to Verify

1. **Run the specification test**

   ```powershell
   cd backend
   $env:DB_PASSWORD='gpa_secret_change_me'; php artisan test --filter=FreeSearchMonetizationCleanupTest
   ```

   Expected: 6 tests and 58 assertions pass.

2. **Run the complete backend regression**

   ```powershell
   cd backend
   $env:DB_PASSWORD='gpa_secret_change_me'; php artisan test
   ```

   Expected: 47 tests and 221 assertions pass.

3. **Validate frontend and Compose**

   ```powershell
   cd frontend
   npm run lint
   npm run build
   cd ..
   docker compose config --quiet
   ```

   Expected: lint/build/config succeed; only the pre-existing `brand.tsx` Fast Refresh warning may appear.

4. **Manual user flow**

   Search a stored game as a guest, register, choose a target price and marketplace item types, then open the cabinet.

   Expected: no Pro/quota UI; the favorite and active alert show the saved target/scopes; ads appear only after results and in the footer.

## Test Coverage

- Tests added: 6 feature tests in one specification file
- Coverage: not measured (no coverage driver configured)
- Status: passing — Laravel 47/47, frontend lint/build, Pint, Compose config and desktop/mobile browser smoke

## Ready for Review

- [x] All acceptance criteria met
- [x] Tests passing
- [x] No critical issues
- [x] Documentation updated
- [x] Developer notes captured

## Developer Notes

- Rollback recreates the old columns/table but cannot restore deleted subscription or quota values.
- Old deployment environment files may still contain ignored quota/Pro keys; active configuration no longer reads them.
- A pending source-refresh notice can coexist with the last stored prices; this is existing freshness behavior and belongs to the following release-readiness work item, not this cleanup.
- Docker Desktop and the PostgreSQL container were left running; temporary Laravel/Vite processes used for browser testing were stopped.

---
*Generated by specs.md - fabriqa.ai FIRE Flow Run run-agregator-games-vibecoding-011*
