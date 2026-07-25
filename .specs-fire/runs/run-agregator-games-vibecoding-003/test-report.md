# Test Report: Поиск из серверного хранилища

- Backend: 22 passed, 83 assertions.
- New stored-search coverage: 3 tests, 12 assertions.
- Frontend: `npm run build` passed.
- Coverage instrumentation is not configured.

## Acceptance validation

- Canonical prices map to existing Steam/market cards without source calls.
- Unknown `appid` queues a refresh and returns `refreshing=true`.
- Announced games return no marketplace offers.
- Existing backend suite remains green.
