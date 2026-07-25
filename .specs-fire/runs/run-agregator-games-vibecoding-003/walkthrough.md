# Implementation Walkthrough: Поиск из серверного хранилища

`StoredPriceSearchService` now reads canonical games, current prices and refresh states, then maps them back to the existing frontend card contract. `PriceController` uses this read model for `/api/prices`; an unknown app creates a placeholder and queues Steam instead of waiting for external stores.

The UI keeps its cards and adds a compact background-refresh status. `/api/search` consults the local catalog first and calls Steam only if that catalog has no candidates.

## Verification

- `php artisan test` — 22 passed, 83 assertions.
- `npm run build` in `frontend/` — passed.

## Developer note

Search quotas and Pro remain unchanged intentionally; their removal is the dedicated `free-search-monetization-cleanup` work item.
