---
id: telegram-access-security-hardening
title: Безопасная Telegram-привязка и доступ бота
intent: unified-price-watchlist-mvp
complexity: high
mode: validate
status: completed
depends_on:
  - telegram-identity-merge
  - telegram-bot-shared-interface
created: 2026-07-25T18:51:47Z
run_id: run-agregator-games-vibecoding-009
completed_at: 2026-07-25T19:02:51.996Z
---

# Work Item: Безопасная Telegram-привязка и доступ бота

## Description

Устранить уязвимость сопоставления Telegram-пользователей в групповом чате, сделать отвязку Telegram полной и безопасной, а отсутствие production-конфигурации OIDC — понятным управляемым состоянием без HTTP 500.

## Acceptance Criteria

- [ ] Внутренний Telegram API принимает аккаунтный сценарий только из личного чата; групповой или несовпадающий `chat_id` не может дать доступ к чужому аккаунту.
- [ ] Аккаунт всегда разрешается по `ExternalIdentity(provider=telegram, provider_subject=telegram_user_id)`; `chat_id` используется только как адрес доставки.
- [ ] Повторный `/start` не создаёт второй аккаунт и не меняет владельца identity.
- [ ] Отвязка Telegram в одной транзакции отзывает доступ бота, удаляет Telegram identity и неиспользованные link-коды; дальнейший bot API запрос получает 401.
- [ ] Старые записи с групповым chat_id безопасно отключаются от доставки без автоматического переназначения аккаунта.
- [ ] OIDC begin при отсутствии server-side конфигурации возвращает понятный 503 без внутренней ошибки; UI не предлагает неработающий вход.
- [ ] Добавлены регрессионные feature-тесты группового чата, отвязки, повторного `/start`, старых записей и OIDC readiness.

## Technical Notes

Telegram Login использует отдельные OIDC-параметры и не может работать только по токену BotFather. Реальные client id, secret и redirect URI остаются исключительно в production environment; в репозиторий добавляется только описание переменных.

## Dependencies

- telegram-identity-merge
- telegram-bot-shared-interface
