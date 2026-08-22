---
run: run-agregator-games-vibecoding-011
work_item: free-search-monetization-cleanup
intent: unified-price-watchlist-mvp
generated: 2026-07-26T08:55:23Z
status: passing
---

# Test Report: Бесплатный поиск и удаление Pro

## Summary

| Category | Passed | Failed | Skipped | Coverage |
|----------|--------|--------|---------|----------|
| Laravel feature/integration suite | 47 | 0 | 0 | Not measured |
| Focused specification suite | 6 | 0 | 0 | 58 assertions |
| Frontend static checks | 2 | 0 | 0 | Not measured |
| Browser smoke scenarios | 4 | 0 | 0 | Manual acceptance |
| **Total** | **59** | **0** | **0** | **Not measured** |

The final Laravel run completed with 47 tests and 221 assertions. No coverage driver is configured, so no percentage is fabricated.

## Acceptance Criteria Validation

- ✅ **Pro, тарифы, платежные запросы и промокоды удалены из сайта и API** — prohibited routes return `404`; active backend, frontend, configuration and documentation paths contain no matching product contract.
- ✅ **Планы и дневные квоты не влияют на поиск** — two consecutive guest/auth requests pass under legacy limit configuration and responses contain no `quota` object.
- ✅ **Legacy schema is removed by a new migration** — the original migrations remain unchanged; upgrade and clean PostgreSQL `migrate:fresh` both pass. Rollback is explicitly structure-only.
- ✅ **Guests and users search free with technical rate limiting** — free-search contract passes and request 21 to `/api/prices` receives `429` from the retained `throttle:20,1` middleware; `/api/search` retains `throttle:30,1`.
- ✅ **Ads are non-blocking and do not split results** — browser checks confirm `after_results` follows the complete result block and `footer` is inside the real footer on desktop and mobile; no popup/fullscreen ad is rendered.
- ✅ **Ads and partner settings do not influence offers** — regression assertions preserve stored offer composition/order while ad/partner settings vary.
- ✅ **An existing non-Pro user retains account data** — migration/auth regression preserves login, history and favorites; browser flow registered a user, saved a 1200 ₽ target with Steam official, Plati key and GGsel gift scopes, and displayed the same data in the cabinet.

## Tests Written

### Integration Tests

- `backend/tests/Feature/FreeSearchMonetizationCleanupTest.php` — free guest/auth search, removed endpoints, public user contract, migration preservation, neutral ad contract and technical throttle.

### Browser Scenarios

- Desktop stored-price result: Steam 1499 ₽, Plati 999 ₽ and GGsel 1099 ₽ rendered without Pro/quota UI.
- Registration and authenticated session: synthetic local account created successfully.
- Favorite/alert/cabinet: target price and cross-source item types saved and shown in active alerts and favorites.
- Mobile 390×844: three-item bottom navigation, Plati/GGsel tabs, both ad placements and footer verified; browser console contained no errors.

## Test Commands

```powershell
# Focused specification
$env:DB_PASSWORD='gpa_secret_change_me'; php artisan test --filter=FreeSearchMonetizationCleanupTest

# Full backend regression
$env:DB_PASSWORD='gpa_secret_change_me'; php artisan test

# Clean PostgreSQL schema (executed against gpa_test)
php artisan migrate:fresh --force

# Frontend
npm run lint
npm run build

# PHP formatting and Compose validation
vendor\bin\pint --test routes\api.php app\Http\Controllers\Api\AdminController.php app\Http\Controllers\Api\AdsController.php app\Http\Controllers\Api\PriceController.php app\Models\User.php app\Services\AggregatorService.php app\Services\StoredPriceSearchService.php database\migrations\2026_07_25_194100_remove_pro_and_search_quotas.php tests\Feature\FreeSearchMonetizationCleanupTest.php
docker compose config --quiet
```

## Coverage Details

Coverage details are not available because the repository has no configured PHP or frontend coverage driver. Behavioral coverage is supplied by the focused feature suite, the complete Laravel suite and desktop/mobile browser scenarios.

## Issues Found

| Issue | Severity | Status |
|-------|----------|--------|
| Mobile bottom navigation retained a four-column grid after the Pro button was removed. | Medium | Fixed to three columns; lint/build/browser verification passed. |
| Footer ad was rendered before the semantic footer. | Medium | Moved inside the footer; desktop/mobile browser verification passed. |
| Controller imports did not match Pint ordering. | Low | Mechanically sorted; Pint and full tests pass. |
| Docker image rebuild could not fetch a base image because Docker Hub TLS timed out during the earlier attempt. | Environment | Compose configuration passed; local Laravel/Vite plus PostgreSQL were used for full browser verification. No application defect observed. |
| `frontend/src/brand.tsx` emits the existing Fast Refresh warning. | Low, pre-existing | Not changed; lint exits successfully. |

## Ready for Completion

- [x] All tests passing
- [ ] Numeric coverage target measured (no coverage driver configured)
- [x] All acceptance criteria validated
- [x] No critical issues open

---
*Generated by specs.md - fabriqa.ai FIRE Flow Run run-agregator-games-vibecoding-011*
