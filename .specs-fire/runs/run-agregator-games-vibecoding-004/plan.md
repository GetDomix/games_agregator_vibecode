---
run: run-agregator-games-vibecoding-004
work_item: cross-source-alert-settings
intent: unified-price-watchlist-mvp
mode: validate
checkpoint: plan
checkpoint_state: awaiting_approval
---

# Implementation Plan: Избранное и настройка алертов

1. Добавить `favorite_alerts` и `favorite_alert_scopes`, PostgreSQL constraints и Steam-only backfill для существующих favorites.
2. Создать модели/relations и сервис нормализации scopes.
3. Изменить Favorite API: принимать alert settings, возвращать alert state/scopes, перестать принимать client prices.
4. Добавить rearm endpoint; изменение цели автоматически делает alert active.
5. Обновить текущую форму избранного минимальными переключателями Steam/Plati/GGsel и видами key/gift/account/rent.
6. Добавить feature tests: defaults, invalid combinations, price validation, triggered→rearm и legacy migration; прогнать backend/frontend проверки.

## Files to Create

- `backend/database/migrations/*_create_favorite_alert_settings.php`
- `backend/app/Models/FavoriteAlert.php`
- `backend/app/Models/FavoriteAlertScope.php`
- `backend/app/Services/FavoriteAlertSettingsService.php`
- `backend/tests/Feature/CrossSourceAlertSettingsTest.php`

## Files to Modify

- `backend/app/Models/Favorite.php`
- `backend/app/Http/Controllers/Api/FavoriteController.php`
- `backend/routes/api.php`
- `frontend/src/App.tsx`

Reference: `.specs-fire/intents/unified-price-watchlist-mvp/work-items/cross-source-alert-settings-design.md`

---
*Checkpoint 2 awaiting approval. No application code has changed in this run.*
