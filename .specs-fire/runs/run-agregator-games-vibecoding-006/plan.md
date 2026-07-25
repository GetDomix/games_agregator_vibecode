---
run: run-agregator-games-vibecoding-006
work_item: alert-evaluation-delivery
intent: unified-price-watchlist-mvp
mode: validate
checkpoint: plan
checkpoint_state: awaiting_approval
---

# Implementation Plan: Срабатывание и доставка алертов

## Approach

1. Добавить неизменяемые `alert_events` и повторяемые `alert_deliveries`. Уникальность `favorite_alert_id` гарантирует одно срабатывание на цикл жизни alert-а.
2. После успешного применения каждой нормализованной цены вызывать `AlertEvaluationService`: он загрузит только `active` alert-ы этой игры, сопоставит их scopes с `current_game_prices` и выберет детерминированное самое дешёвое допустимое предложение.
3. В одной транзакции создать event, перевести alert в `triggered` и поставить отдельную queued job доставки после commit. Повтор refresh job не создаст второй event.
4. `DeliverAlertEventJob` создаёт/обновляет delivery по идемпотентному ключу, отправляет сообщение через существующий `TelegramNotifyService`, использует Laravel retry/backoff и не создаёт новый event при ошибке Telegram.
5. Добавить API активных/сработавших alert-ов и history событий/доставок. Убрать старый `radar:scan` из расписания, чтобы он не отправлял параллельные Steam-only сообщения.
6. Добавить PostgreSQL feature-тесты: filter по source/kind, достижение цели, website target flow, deduplication, Telegram retry and rearm.

## Files to Create

| File | Purpose |
|------|---------|
| `backend/database/migrations/*_create_alert_events_and_deliveries.php` | Схема событий и попыток доставки с ограничениями уникальности и индексами. |
| `backend/app/Models/AlertEvent.php` | Снимок сработавшего предложения и связи с alert/favorite/user. |
| `backend/app/Models/AlertDelivery.php` | Состояние и история доставки одного события. |
| `backend/app/Services/AlertEvaluationService.php` | Оценка scopes, цены и атомарное создание события. |
| `backend/app/Jobs/DeliverAlertEventJob.php` | Очередная идемпотентная доставка Telegram. |
| `backend/app/Http/Controllers/Api/AlertController.php` | Списки активных/сработавших alert-ов и история. |
| `backend/tests/Feature/AlertEvaluationDeliveryTest.php` | Сквозные сценарии оценки, дедупликации и повторной доставки. |

## Files to Modify

| File | Changes |
|------|---------|
| `backend/app/Services/GamePriceRefreshService.php` | Запуск оценки после успешного сохранения канонических цен. |
| `backend/app/Models/FavoriteAlert.php` | Связь с событиями. |
| `backend/app/Models/Favorite.php` | Сериализация состояния/истории alert-а при необходимости API. |
| `backend/app/Services/FavoriteAlertSettingsService.php` | Rearm и изменение цели сохраняют согласованное lifecycle-состояние. |
| `backend/app/Http/Controllers/Api/FavoriteController.php` | Возврат актуального alert-состояния без старой Steam-only эвристики. |
| `backend/app/Http/Controllers/Api/TelegramController.php` | Legacy trigger направляет только на каноническую очередь без старого radar scan. |
| `backend/routes/api.php` | Новые защищённые endpoints истории алертов. |
| `backend/bootstrap/app.php` | Удаление старого `radar:scan` из расписания, чтобы исключить двойные уведомления. |

## Tests

| Test File | Coverage |
|-----------|----------|
| `backend/tests/Feature/AlertEvaluationDeliveryTest.php` | Scopes, target condition, event uniqueness, triggered/rearm lifecycle, delivery retry and API history. |
| `backend/tests/Feature/CentralPriceRefreshTest.php` | Обновить проверку вызова оценки после сохранения цены. |

## Based on Design Doc

Reference: `.specs-fire/intents/unified-price-watchlist-mvp/work-items/alert-evaluation-delivery-design.md`

---
*Awaiting checkpoint 2 approval. No application code has changed in this run.*
