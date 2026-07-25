---
run: run-agregator-games-vibecoding-006
work_item: alert-evaluation-delivery
generated: 2026-07-25
---

# Test Report: Alert evaluation and delivery

## Results

- Backend: `php artisan test` — **32 passed**, 127 assertions.
- Formatting: Pint passed for all changed PHP files.
- Scheduler: only `prices:dispatch-due` and `prices:prune-history` remain; legacy `radar:scan` is no longer scheduled.

## Acceptance validation

- [x] Evaluator matches only the alert's selected source and offer kind.
- [x] An active target-price alert triggers when the cheapest admissible price is less than or equal to the target.
- [x] Unique `(favorite_alert_id, alert_cycle)` prevents duplicate events for repeated work or duplicate offers.
- [x] Event stores source, offer kind, price, title, URL and observation time.
- [x] Delivery uses one durable record per event; Telegram failure retries the same event and does not create another.
- [x] Triggered alerts do not evaluate again; rearm increments cycle and allows a new history-preserving event.
- [x] Authenticated API returns alert status and event/delivery history for its own user only.

## Notes

The queue worker must be running in the deployed environment for delivery retries. A user without a linked Bot API chat receives a persisted event with delivery status `skipped`; they can rearm the alert after linking the chat.
