---
id: telegram-identity-merge
title: Telegram-идентификация и объединение аккаунтов
intent: unified-price-watchlist-mvp
complexity: high
mode: validate
status: completed
depends_on:
  - cross-source-alert-settings
created: 2026-07-25T13:47:35Z
run_id: run-agregator-games-vibecoding-005
completed_at: 2026-07-25T17:15:23.893Z
---

# Work Item: Telegram-идентификация и объединение аккаунтов

## Description

Сделать Telegram и сайт двумя идентичностями одного аккаунта, поддержать Telegram-профиль до регистрации на сайте и безопасное объединение данных через официальный Telegram Login.

## Acceptance Criteria

- [ ] Официальный Telegram OIDC использует Authorization Code Flow, PKCE, `state` и серверную проверку подписи и claims.
- [ ] Telegram-пользователь может иметь избранное и алерты до появления email-аккаунта.
- [ ] Связывание объединяет игры, площадки и виды без дубликатов.
- [ ] При конфликте целевая цена сайта имеет приоритет; при её отсутствии берётся Telegram-цель.
- [ ] Один Telegram ID не может быть тихо перепривязан к другому аккаунту.
- [ ] Операция объединения атомарна, повторяемая и создаёт понятный пользователю отчёт.
- [ ] Секрет OIDC не попадает во frontend, логи или репозиторий.

## Technical Notes

Рекомендуется отдельная таблица внешних идентичностей вместо генерации фиктивных email. Старый `/start CODE` удаляется только после рабочего OIDC-пути и миграции существующих связей.

## Dependencies

- cross-source-alert-settings
