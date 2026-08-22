---
run: run-agregator-games-vibecoding-007
work_item: website-watchlist-ui
intent: unified-price-watchlist-mvp
mode: confirm
checkpoint: plan
checkpoint_state: awaiting_approval
---

# Implementation Plan: Интерфейс сайта для избранного и алертов

## Approach

1. Заменить `confirm` и `prompt` при добавлении в избранное на модальное окно: целевая цена, Steam/Plati/GGsel и типы key/gift/account/rent. Опасные варианты аккаунт/аренда будут выключены по умолчанию.
2. Расширить данные избранного серверной свежестью по источникам и статусом релиза, не делая запросов к магазинам из интерфейса.
3. В кабинете загрузить `/api/me/favorites`, `/api/me/alerts` и `/api/me/alerts/events`; показать вкладки «Активные», «Сработавшие» и «История доставок».
4. Добавить редактирование цели/scopes и действие «Активировать снова» для сработавшего alert-а. Сохранять через существующие API, затем перезагружать локальные данные.
5. Явно показать состояние ожидания релиза и свежесть/ошибку каждого источника; Telegram-блок будет отражать результат официальной привязки, а не только старую привязку чата.
6. Сохранить текущий визуальный язык. Логику модального окна и alert-данных вынести из `App.tsx` в небольшие компоненты, без общего редизайна.

## Files to Create

| File | Purpose |
|------|---------|
| `frontend/src/components/AlertSettingsModal.tsx` | Форма добавления и редактирования цели/scopes без браузерных prompt. |
| `frontend/src/components/WatchlistAlerts.tsx` | Вкладки активных/сработавших alert-ов и история доставок. |
| `frontend/src/watchlist.ts` | Общие TypeScript-типы и преобразования API-данных. |

## Files to Modify

| File | Changes |
|------|---------|
| `frontend/src/App.tsx` | Открытие формы из карточки, загрузка watchlist/alerts, новый кабинет и Telegram identity status. |
| `frontend/src/styles.css` | Локальные стили модального окна, scopes, alert-статусов и freshness. |
| `backend/app/Models/Favorite.php` | Сериализация статуса релиза и source freshness при загруженных отношениях. |
| `backend/app/Http/Controllers/Api/FavoriteController.php` | Eager-load `game.sourceStates` для списка избранного. |
| `backend/app/Http/Controllers/Api/DashboardController.php` | Не считать legacy Steam-only «на цели» главным состоянием alert-а. |
| `backend/tests/Feature/CrossSourceAlertSettingsTest.php` | Проверка freshness/release полей в API избранного. |

## Tests and Verification

| Scope | Verification |
|-------|--------------|
| Backend contract | `php artisan test` после расширения ответа избранного. |
| Frontend | `npm run lint` и `npm run build`. |
| Manual | Добавить игру через форму, включить/выключить scopes, изменить цель, rearm сработавшего alert-а, проверить announced status и Telegram identity result. |

---
*Awaiting plan approval. No application code has changed in this run.*
