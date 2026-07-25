---
run: run-agregator-games-vibecoding-007
work_item: website-watchlist-ui
intent: unified-price-watchlist-mvp
mode: confirm
generated: 2026-07-25
---

# Implementation Walkthrough: Website watchlist UI

## Summary

The website now uses a real alert-settings modal instead of browser prompts and presents a common watchlist dashboard in the Cabinet. Users can inspect active and triggered alerts, see the exact delivery status, edit target/scopes, and rearm a triggered alert.

## Structure

```text
Game card -> AlertSettingsModal -> /api/me/favorites
Cabinet -> /api/me/favorites + /api/me/alerts
        -> WatchlistAlerts -> edit or rearm
```

## Key details

- Steam is always selected in the form; marketplace key/gift/account/rent choices are explicit and account/rent begin unchecked.
- API supplies release status plus source freshness/error state from canonical storage; the UI never reaches out to stores.
- The Cabinet uses the new alert lifecycle API, not the old Steam-only “price hit” heuristic.
- Telegram identity confirmation and Bot API chat binding are shown as separate facts, which reflects the actual model.

## Files changed

Created the modal, alert dashboard and shared API types. Updated the main application integration/styles and enriched the favorite API with freshness/release fields.

## How to verify

1. Start the frontend and backend, sign in, search a game and select the favorite star.
2. Expected: the modal opens instead of a browser prompt; select scopes and a price, then save.
3. Open Cabinet. Expected: the game shows its alert target/scopes and freshness; an announced game says it is awaiting release.
4. Trigger an alert via a stored price update. Expected: it appears in “Сработавшие” with Telegram delivery status.
5. Select “Активировать снова”. Expected: it moves to “Активные” and can create a new event later.

## Verification status

- [x] Backend tests: 33 passed, 131 assertions.
- [x] Frontend lint and production build passed (one unrelated existing lint warning remains).
- [ ] Visual manual pass remains after starting the local application.

## Developer note

The main `App.tsx` still owns page routing/state, but watchlist-specific UI and types are now isolated so later bot/API changes do not further enlarge the alert form logic.
