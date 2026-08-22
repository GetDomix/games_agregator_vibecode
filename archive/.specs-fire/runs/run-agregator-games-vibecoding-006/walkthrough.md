---
run: run-agregator-games-vibecoding-006
work_item: alert-evaluation-delivery
intent: unified-price-watchlist-mvp
mode: validate
generated: 2026-07-25
---

# Implementation Walkthrough: Alert evaluation and delivery

## Summary

The backend now turns a matching stored price into a single durable alert event and delivers it to Telegram from the queue. It uses the user’s source/type scopes, preserves a full event and delivery record, and stops evaluating an alert after it has triggered until the user rearms it.

## Structure

```text
Canonical price refresh
    -> AlertEvaluationService
    -> AlertEvent (one per alert cycle)
    -> AlertDelivery
    -> DeliverAlertEventJob
    -> TelegramNotifyService
```

## Domain model

| Entity | Responsibility |
|---|---|
| `FavoriteAlert.cycle` | Separates lifecycle rounds after a target change or rearm. |
| `AlertEvent` | Immutable snapshot of the exact matching offer. |
| `AlertDelivery` | One Telegram delivery lifecycle with attempt count, timestamps and error. |

## Key details

- Evaluation reads only `current_game_prices`, so it never calls an external store.
- Matching is a direct source + offer-kind comparison against alert scopes.
- Database locking, event uniqueness and a unique queue job jointly prevent duplicate notifications.
- The old scheduled Steam-only scan was removed. Canonical price updates are now the single trigger.
- An unlinked Bot API chat records a skipped delivery rather than losing the event silently.

## Security and reliability

| Concern | Approach |
|---|---|
| Cross-account history access | Alert API always filters by current authenticated user. |
| Duplicate jobs | Unique event-cycle constraint and unique job ID. |
| Telegram outages | Laravel queue retries with 60s, 5m and 15m backoff; final failure is recorded. |
| HTML injection in Telegram | Game title and offer URL are escaped before message formatting. |

## Files

Created event/delivery schema, models, evaluator, job, API controller and feature test. Updated canonical refresh integration, alert lifecycle, routes and scheduler.

## How to verify

1. Run the queue worker and scheduled dispatcher in a deployed environment.
2. Add a favorite with target price and selected scopes, then ensure a canonical update writes a matching price.
3. Expected: one event is created, alert becomes `triggered`, and one Telegram message is sent.
4. Run the same update again. Expected: no second event or message.
5. Reactivate the alert. A later matching update creates a new event for the next cycle; the old event stays in `/api/me/alerts/events`.

## Verification status

- [x] `php artisan test`: 32 tests, 127 assertions.
- [x] Pint passed.
- [x] Scheduler no longer includes legacy `radar:scan`.
- [x] No critical review findings.

## Developer note

The OIDC Telegram identity and Bot API chat binding remain distinct: identity merge makes data common, while a `telegram_chat_id` is still needed to receive an actual Bot API message.
