---
work_item: alert-evaluation-delivery
intent: unified-price-watchlist-mvp
created: 2026-07-25
mode: validate
checkpoint_1: approved
---

# Design: Срабатывание и доставка алертов

## Summary

После каждого успешного сохранения серверных цен система проверяет активные алерты этой игры по выбранным пользователем площадкам и типам предложений. Подходящая цена создаёт один неизменяемый event, который отдельная задача доставляет в Telegram с повторными попытками.

## Key Decisions

| Decision | Choice | Rationale |
|---|---|---|
| Trigger | После сохранения актуальных цен | Нет лишних запросов к магазинам и событие основано на единой серверной базе. |
| Matching | Только scopes alert-а | Не смешиваются ключи, гифт, аккаунты, аренда и невыбранные площадки. |
| Deduplication | Уникальный event на `favorite_alert_id` | Повтор задания/несколько одинаковых цен не создают повторный alert. |
| Delivery | Отдельная queued job и записи доставок | Временная ошибка Telegram не отменяет событие и не требует повторной оценки. |
| State | `active` → `triggered` при создании event | Повторное уведомление возможно только после rearm/изменения цели. |
| History | Event с предложением + delivery attempts | Один источник данных для сайта и бота. |

## Domain Model

```text
FavoriteAlert 1──* AlertEvent 1──* AlertDelivery
FavoriteAlert 1──* FavoriteAlertScope
AlertEvent ──> selected CurrentGamePrice / GamePriceObservation snapshot
```

- `AlertEvent`: снимок подходящего предложения — площадка, вид, цена, название, URL и время наблюдения.
- `AlertDelivery`: статус доставки, число попыток, ошибка и время успешной отправки.

## Technical Approach

```text
RefreshGameSourceJob
  -> GamePriceRefreshService stores current prices
  -> AlertEvaluationService evaluates active alerts for game
  -> AlertEvent (unique per alert) + alert.status=triggered
  -> DeliverAlertEventJob
  -> TelegramNotifyService
  -> AlertDelivery status updated / retried
```

API предоставляет активные и сработавшие alert-ы с event/delivery history. Существующий endpoint rearm продолжает переводить alert в `active`; новое событие возможно при следующем подходящем обновлении цены.

## Risks and Mitigations

| Risk | Impact | Mitigation |
|---|---|---|
| Повтор refresh job | Duplicate message | Database unique constraint and idempotent delivery key. |
| Telegram temporarily unavailable | Missed notification | Event persists; queued delivery retries with backoff. |
| Несколько подходящих предложений | Ambiguous notification | Use minimum price; deterministic source/kind ordering. |
| Chat not linked | No Telegram target | Save event, mark delivery pending/skipped with visible history. |
| Legacy radar scan | Two sources of alerts | Replace its decision path; it may no longer create price alerts. |

## Implementation Checklist

1. Create event/delivery schema and Eloquent models.
2. Add evaluator invoked after canonical current-price updates.
3. Add unique event creation and alert state transition transaction.
4. Add queued Telegram delivery with retry-safe state updates.
5. Expose alert lists and event/delivery history API.
6. Update/reduce legacy radar scan path to avoid duplicate notifications.
7. Add PostgreSQL feature tests for scopes, target condition, deduplication, retry and rearm.
