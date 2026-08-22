---
run: run-agregator-games-vibecoding-009
work_item: telegram-access-security-hardening
intent: unified-price-watchlist-mvp
mode: validate
checkpoint: plan
approved_at: pending
---

# Implementation Plan: Безопасная Telegram-привязка и доступ бота

## Approach

Выполнить work item ровно по утверждённому design-spec: сначала зафиксировать все уязвимые сценарии feature-тестами, затем сделать backend единственным источником авторизации Telegram identity, безопасно отключить старые group-chat delivery routes миграцией, отозвать identity при unlink и отдать UI явный non-secret статус OIDC. Бот дополнительно перестанет создавать аккаунтный сценарий вне личного чата. Реальные OIDC-секреты не создаются и не изменяются в репозитории.

## Files to Create

| File | Purpose |
|------|---------|
| `backend/database/migrations/*_harden_telegram_personal_chat_links.php` | Сбросить только небезопасные отрицательные group chat id без переноса identities или избранного. |
| `backend/tests/Feature/TelegramAccessSecurityTest.php` | Изолированные edge-case тесты private/group chat, повторного start, unlink и OIDC readiness. |

## Files to Modify

| File | Changes |
|------|---------|
| `backend/app/Services/TelegramBotUserService.php` | Проверить private-chat контракт, разрешать user только по Telegram identity, сохранить блокировки строк и идемпотентность. |
| `backend/app/Http/Controllers/Api/TelegramController.php` | Выполнить полную отвязку транзакционно; безопасно преобразовать OIDC-not-ready в 503; раскрыть readiness в status. |
| `backend/app/Services/TelegramOidcService.php` | Добавить явный проверяемый признак полноты OIDC-конфигурации и domain exception без секретов. |
| `backend/routes/api.php` | Настроить точный HTTP-ответ для недоступного OIDC, если это нельзя безопасно сделать в контроллере. |
| `backend/tests/Feature/TelegramReleaseRegressionTest.php` | Превратить четыре QA-регрессии в обязательные зелёные тесты. |
| `bot/main.py` | До вызова общего backend отклонять group/supergroup и объяснять, что аккаунтный сценарий доступен в личном чате. |
| `bot/tests/test_main_handlers.py` | Проверить, что group chat не инициирует account session и не отправляет карточку. |
| `frontend/src/App.tsx` | Использовать `oidc_available`, не открывать неработающий popup и показать честный статус настройки. |
| `backend/.env.example` | Документировать только названия нужных OIDC-переменных и redirect URI. |

## Tests

| Test File | Coverage |
|-----------|----------|
| `backend/tests/Feature/TelegramReleaseRegressionTest.php` | Исправляет воспроизведения shared chat, unlink, quota/Pro boundary оставляя соседние work items нетронутыми. |
| `backend/tests/Feature/TelegramAccessSecurityTest.php` | Private-only session, re-start idempotency, migration behaviour, 503 readiness и отсутствие internal error. |
| `bot/tests/test_main_handlers.py` | Сценарий group chat не обращается к backend-сессии. |
| Existing frontend build | Проверяет совместимость статуса и UI Telegram Login. |

## Technical Details

1. Private chat проверяется в двух местах: обработчик Telegram update и Laravel service. Backend принимает только численно равные `telegram_user_id` и `chat_id` для account endpoints; это не зависит от поведения клиента.
2. `resolve()` сначала блокирует и читает identity по `telegram_user_id`. Если identity нет, создаёт новый Telegram-first account только для личного чата. Поиск `User` по `telegram_chat_id` исключается из выбора владельца.
3. `unlink()` в одной DB-транзакции удаляет Telegram external identities конкретного пользователя, неиспользованные link-коды и delivery-поля; access tokens этого Telegram-first account остаются только если они относятся к сайту, поэтому bot-доступ после unlink проверяется отсутствием identity.
4. Миграция только очищает отрицательные numeric chat ids. Она не переносит favorites, alerts или external identities, потому что владельца группы нельзя определить достоверно.
5. `TelegramOidcService` получает `isConfigured()`. Begin возвращает контролируемый 503 с русским public detail; callback продолжает скрывать исключения. Фронтенд показывает кнопку только когда `oidc_available` true.
6. Проверки: чистая PostgreSQL база, Laravel full suite, bot tests in container, frontend lint/build. До production-deploy отдельно проверяется OIDC readiness без чтения секретов.

## Based on Design Doc

Reference: `.specs-fire/intents/unified-price-watchlist-mvp/work-items/telegram-access-security-hardening-design.md`

---
*Plan awaiting approval at checkpoint 2. Implementation does not start before approval.*
