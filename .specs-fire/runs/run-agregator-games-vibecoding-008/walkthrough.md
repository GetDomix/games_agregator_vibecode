---
run: run-agregator-games-vibecoding-008
work_item: telegram-bot-shared-interface
intent: unified-price-watchlist-mvp
generated: 2026-07-25T18:33:00Z
mode: confirm
---

# Implementation Walkthrough: Полноценный интерфейс Telegram-бота

## Summary

Telegram-бот стал вторым интерфейсом общего аккаунта Игроскана. Он создаёт или находит Telegram-профиль через Laravel, ищет игры, отправляет PNG-карточку цен в стиле сайта, управляет избранным и alert-ами без собственной базы или price scanner-а.

## Structure Overview

```text
Telegram private chat
  -> aiogram commands, callbacks and temporary FSM
     -> protected Laravel internal API
        -> Telegram identity + favorites + alerts + canonical price storage
     <- card data
  -> local Pillow renderer -> PNG photo + detailed text/actions
```

Laravel остаётся владельцем правил scopes, целевой цены, release status, очереди неизвестных игр и lifecycle alert-а. Python получает готовые данные и только визуально представляет их пользователю.

## Files Changed

### Created

| File | Purpose |
|------|---------|
| `backend/app/Http/Middleware/EnsureRadarServiceToken.php` | Проверка service token для bot API. |
| `backend/app/Services/TelegramBotUserService.php` | Разрешение или создание общего Telegram пользователя/identity. |
| `backend/app/Http/Controllers/Api/TelegramBotController.php` | Internal endpoints поиска, карточки, избранного и alert-ов. |
| `backend/tests/Feature/TelegramBotInterfaceTest.php` | Контрактные проверки общего аккаунта. |
| `bot/ui.py` | Тексты, escaping и inline-keyboards. |
| `bot/card_renderer.py` | Pillow PNG-карточка в палитре сайта. |
| `bot/tests/*` | Тесты HTTP-клиента, UI и PNG renderer-а. |

### Modified

| File | Changes |
|------|---------|
| `backend/routes/api.php` | Добавлена защищённая группа `/api/internal/telegram/*`. |
| `bot/api_client.py` | Типизированный клиент Laravel с timeouts и едиными ошибками. |
| `bot/main.py` | Search/card/favorites/alerts/rearm и FSM настройки alert-а. |
| `bot/requirements.txt`, `bot/Dockerfile` | Pillow и DejaVu fonts для стабильного PNG в контейнере. |
| `bot/README.md`, `bot/.env.example`, `docker-compose.yml` | Актуальный shared-account flow; удалён устаревший in-bot scheduler setting. |

## Key Implementation Details

1. `/start` без кода создаёт Telegram-only profile. `/start CODE` сначала выполняет старую site-link привязку, затем регистрирует Telegram identity на этом же user.
2. После ввода названия бот отправляет `typing`, получает candidates через Laravel и по выбору шлёт PNG-карточку. Под фото остаётся текст с детальными группами предложений и кнопками действий.
3. `announced` игра отображает Steam release state и не показывает Plati/GGsel; неизвестная игра возвращает background-refresh state, а не синхронно опрашивает магазины.
4. Настройка alert-а выбирает scopes inline-кнопками, затем принимает одну целевую цену. Все изменения немедленно сохраняются Laravel и видны сайту.

## Security Considerations

| Concern | Approach |
|---------|----------|
| Доступ bot API | `X-Radar-Token`, constant-time comparison и route throttling. |
| Identity takeover | Telegram user id — ключ identity; username не используется как идентификатор; конфликт users возвращает 409. |
| HTML messages | Названия игр экранируются перед `parse_mode=HTML`. |
| Image download | Явный timeout, лимит 5 MB и локальный fallback без обложки. |

## Performance Considerations

| Requirement | Implementation |
|-------------|----------------|
| Не опрашивать магазины на каждом действии | Карточка читает canonical price storage Laravel. |
| Не блокировать UX | Telegram получает `typing`; неизвестная игра ставится в queue. |
| Компактное сообщение | Фото содержит summary, длинные данные остаются обычным текстом. |

## Decisions Made

| Decision | Choice | Rationale |
|----------|--------|-----------|
| Визуальная карточка | Pillow PNG в bot container | Не нужен браузер или внешний image service; стиль стабилен в Docker. |
| Хранение состояния | Только Laravel + временный aiogram FSM | Сайт и бот не расходятся по избранному/alert-ам. |
| Обложка | Steam header image при наличии, fallback иначе | Карточка остаётся рабочей при сбое CDN. |

## Deviations from Plan

План дополнен после подтверждения: пользователь запросил отправку фото-карточки в стиле сайта. Добавлены `card_renderer.py`, Pillow и контейнерные шрифты. Остальная архитектура не изменилась.

## Dependencies Added

| Package | Why Needed |
|---------|------------|
| `Pillow` | Локальный PNG renderer карточки. |
| `fonts-dejavu-core` | Предсказуемый кириллический текст в slim Docker image. |

## How to Verify

1. Запустить backend и bot через Docker Compose с заполненными `TELEGRAM_BOT_TOKEN` и `RADAR_SERVICE_TOKEN`.
2. Открыть личный чат, отправить `/start`, затем написать название игры.
3. Expected: сначала `typing`, затем PNG-карточка с Steam/Plati/GGsel и текст с кнопками.
4. Нажать «Добавить и настроить», выбрать scopes, ввести цену; открыть сайт и убедиться, что игра там появилась.
5. Открыть `/alerts`, затем rearm сработавшего alert-а; на сайте состояние изменится на active.

## Test Coverage

- Tests added: 8 test cases.
- Coverage: инструментальная метрика не настроена.
- Status: Laravel 36/36 (148 assertions); bot 5/5; Docker image build passed.

## Ready for Review

- [x] All acceptance criteria met
- [x] Tests passing
- [x] No critical issues
- [x] Documentation updated
- [x] Developer notes captured

## Developer Notes

`RADAR_SERVICE_TOKEN` должен совпадать у backend и radar-bot. Официальный Telegram Login остаётся механизмом безопасного объединения уже созданных website и Telegram-only профилей. Бот не запускает scheduler: production scheduler по-прежнему обязан работать рядом с Laravel.
