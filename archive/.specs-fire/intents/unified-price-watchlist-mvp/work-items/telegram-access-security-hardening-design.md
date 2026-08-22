---
work_item: telegram-access-security-hardening
intent: unified-price-watchlist-mvp
created: 2026-07-25T18:51:47Z
mode: validate
checkpoint_1: approved
---

# Design: Безопасная Telegram-привязка и доступ бота

## Summary

Сделать Telegram ID единственным идентификатором владельца Telegram-интерфейса, разрешить пользовательский сценарий бота только в личном чате и полностью отзывать этот доступ при отвязке. Не настроенный OIDC не должен приводить к 500.

## Scope

**In Scope:**

- Защита разрешения Telegram identity и личных chat_id.
- Полная отвязка Telegram и безопасная миграция опасных group-chat связей.
- Явный API/UI статус готовности Telegram OIDC.
- Автоматические регрессионные тесты безопасности и отказов конфигурации.

**Out of Scope:**

- Настройка Telegram OAuth application и выдача реальных OIDC-секретов пользователем.
- Удаление legacy deep-link до успешного production E2E официального входа.
- Scheduler, обновление цен, Pro/квоты и визуальный рендер карточки: это следующие утверждённые блоки.

## Key Decisions

| Decision | Choice | Rationale |
|----------|--------|-----------|
| Разрешение аккаунта | Только `ExternalIdentity` по Telegram user id | Chat id в группах общий и не может быть ключом пользователя. |
| Поддерживаемый чат | Только личный чат, `chat_id == telegram_user_id` | Убирает путь к захвату аккаунта из group/supergroup. |
| Повторная сессия | Идемпотентное обновление данных identity | Исключает дубли аккаунтов при повторном `/start`. |
| Отвязка | Транзакционно удалить Telegram identity, delivery fields и неиспользованные коды | Возвращает ожидаемое значение действия «Отвязать»: bot API немедленно теряет доступ. |
| Старые group-chat данные | Сбросить delivery route и потребовать личную перепривязку | Автоматически определить владельца группового чата невозможно и небезопасно. |
| OIDC readiness | Контролируемый 503 и явный флаг готовности | Пользователь получает честный статус вместо Server Error; внутренние детали не раскрываются. |

## Data Models Affected

### Modifies

- **User**: очищает delivery-поля Telegram для старых group-chat связей и при отвязке — предотвращает доставку в общий чат.
- **ExternalIdentity**: Telegram identity становится единственным владельцем bot-сессии и удаляется при явной отвязке.
- **TelegramLinkCode**: неиспользованные коды удаляются при отвязке — старый deep-link не может вернуть доступ.

## Technical Approach

### Architecture

```
Telegram private chat
  -> bot sends telegram_user_id + chat_id
  -> TelegramBotUserService validates personal chat
  -> ExternalIdentity(telegram, telegram_user_id)
  -> common User / favorites / alerts

Website unlink
  -> transaction: revoke identity + delivery route + pending link codes
  -> bot API /favorites => 401

Website OIDC begin
  -> readiness check
  -> authorization URL | safe 503 response
```

### API Changes

- `POST /api/internal/telegram/session` — returns 422 for non-personal chat and 409 for identity ownership conflicts.
- `DELETE /api/telegram/link` — revokes Telegram bot identity as well as chat delivery fields.
- `GET /api/telegram/status` — exposes a non-secret `oidc_available` readiness flag.
- `POST /api/telegram/oidc/begin` — returns a controlled 503 when server OIDC configuration is incomplete.

### Database Changes

```sql
-- Data migration only: clear telegram_chat_id, username and linked_at
-- from users whose chat id denotes a Telegram group (negative numeric id).
-- Do not move identities or favorites automatically.
```

## Dependencies

- `TELEGRAM_OIDC_CLIENT_ID`, `TELEGRAM_OIDC_CLIENT_SECRET` and `TELEGRAM_OIDC_REDIRECT_URI` must be configured only in production environment before real OIDC E2E can succeed.

## Affected Files

| File | Action | Purpose |
|------|--------|---------|
| `backend/app/Services/TelegramBotUserService.php` | Modify | Enforce personal chat and identity-only account resolution. |
| `backend/app/Http/Controllers/Api/TelegramController.php` | Modify | Atomic unlink, OIDC readiness and safe errors. |
| `backend/app/Services/TelegramOidcService.php` | Modify | Domain-specific not-ready signal. |
| `backend/database/migrations/*_harden_telegram_personal_chat_links.php` | Create | Remove unsafe group-chat delivery routes. |
| `backend/tests/Feature/TelegramReleaseRegressionTest.php` | Modify | Turn current failing tests green and extend edge coverage. |
| `frontend/src/App.tsx` | Modify | Show Telegram Login only when readiness is true and render safe unavailable state. |
| `backend/.env.example` | Modify | Document non-secret OIDC variable names. |

## Security Considerations

- **Group chat identity confusion**: Reject group chat requests at both bot and backend boundaries; never select a user by shared chat id.
- **Stale linking code**: Delete unused codes during unlink so an old deep-link cannot reattach access.
- **OIDC secrets**: Keep secrets server-side and return generic errors only.
- **Concurrent requests**: Retain row locks and unique identity constraint; test two starts and competing links.

## Integration Points

| System | Type | Purpose |
|--------|------|---------|
| Telegram Bot | Internal authenticated API | Sends only private-chat session metadata. |
| React website | Sanctum API | Shows status, initiates OIDC and exposes unlink action. |
| Telegram OIDC | External OAuth/OIDC | Provides official identity only when production config exists. |

## Risks & Mitigations

| Risk | Impact | Mitigation |
|------|--------|------------|
| Existing users linked in groups lose notification route | Medium | Clear only unsafe route, retain favorites, show re-link instruction. |
| OIDC credentials are still absent | High | Return honest 503 and hide/disable login CTA; add production readiness test before release. |
| Legacy deep-link and OIDC diverge | Medium | Retain deep-link temporarily but cover both paths with identity ownership tests. |
| Regression breaks bot account creation | High | Feature tests cover first `/start`, repeated `/start`, unlink and re-link. |

## Implementation Checklist

- [ ] Add failing and passing feature tests for personal/group chat semantics.
- [ ] Implement identity-only resolution and private chat validation.
- [ ] Implement atomic unlink and data migration for unsafe historical routes.
- [ ] Add OIDC readiness result and controlled 503 mapping.
- [ ] Update website status and CTA handling.
- [ ] Run Laravel PostgreSQL tests, frontend lint/build and bot contract tests.
- [ ] Verify production config readiness without exposing secrets.

---
*Generated by specs.md - fabriqa.ai FIRE Flow | Checkpoint 1 approved: 2026-07-25T18:51:47Z*
