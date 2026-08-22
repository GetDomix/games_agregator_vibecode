---
run: run-agregator-games-vibecoding-005
work_item: telegram-identity-merge
intent: unified-price-watchlist-mvp
mode: validate
checkpoint: plan
checkpoint_state: awaiting_approval
---

# Implementation Plan: Telegram-идентификация и объединение аккаунтов

1. Добавить migrations/models `external_identities` и одноразовых OIDC transactions; мигрировать текущие telegram fields в identity records.
2. Добавить Telegram OIDC config/env without secrets in frontend, begin endpoint and callback with PKCE/state/nonce, code exchange and JWKS claims verification.
3. Создать transaction-based `TelegramAccountMergeService`: merge favorites, alert targets and scopes without duplicates; website target precedence; return report.
4. Добавить authenticated status/unlink APIs around external identity; keep `/start CODE` compatibility until bot migration is verified.
5. Update website login/link entry point and bot backend calls only where needed.
6. Add security tests: expired/reused state, wrong issuer/audience, already-bound Telegram identity, idempotent merge/conflict precedence.

## Files to Create

- `backend/database/migrations/*_create_external_identities_and_oidc_transactions.php`
- `backend/app/Models/ExternalIdentity.php`
- `backend/app/Models/OidcTransaction.php`
- `backend/app/Services/TelegramOidcService.php`
- `backend/app/Services/TelegramAccountMergeService.php`
- `backend/tests/Feature/TelegramIdentityMergeTest.php`

## Files to Modify

- `backend/config/gpa.php`, `backend/.env.example`
- `backend/app/Models/User.php`, `Favorite.php`, `FavoriteAlert.php`
- `backend/app/Http/Controllers/Api/TelegramController.php`
- `backend/routes/api.php`
- `frontend/src/App.tsx`
- `bot/api_client.py`, `bot/main.py`, `bot/README.md`

## Security gates

- Never expose client secret or verifier to frontend/logs.
- Callback trusts only verified signed token claims and one-time transaction.
- No silent reassignment of an existing Telegram identity.

Reference: `.specs-fire/intents/unified-price-watchlist-mvp/work-items/telegram-identity-merge-design.md`

---
*Checkpoint 2 awaiting approval. No application code has changed in this run.*
