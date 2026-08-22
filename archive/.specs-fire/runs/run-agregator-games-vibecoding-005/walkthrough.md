---
run: run-agregator-games-vibecoding-005
work_item: telegram-identity-merge
intent: unified-price-watchlist-mvp
mode: validate
generated: 2026-07-25
---

# Implementation Walkthrough: Telegram identity merge

## Summary

Telegram and the website now use one account model: Telegram is stored as a unique external identity, while favorites and alert settings stay attached to the account. A logged-in website user can confirm Telegram in an official Telegram Login popup; if that Telegram identity already has its own data, it is merged predictably.

## Structure

```text
Radar page -> authenticated OIDC begin -> Telegram OAuth
Telegram callback -> token/JWKS validation -> external identity
                                           -> atomic favorites and alerts merge
                                           -> same-origin popup message to site
```

## Key decisions

| Decision | Choice | Rationale |
|---|---|---|
| Identity storage | `external_identities` with unique provider subject | One Telegram identity cannot be silently owned by two accounts. |
| Telegram-first account | Nullable email | A bot/Telegram user is valid before they add email credentials. |
| OIDC validation | Auth code, PKCE, state, nonce, JWKS and claims | Keeps the client secret and verifier on the server and prevents replay. |
| Target-price conflict | Website value wins | Matches the agreed product rule. |
| Alert scopes | Union without duplicates | Retains all selected stores and offer kinds. |
| Callback transport | Popup `postMessage` | Does not place a bearer token in the URL or browser history. |

## Files changed

Created backend identity schema/models, OIDC and merge services, and three feature tests. Updated Telegram API/controller/routes, User model, env config, Composer dependency lock and radar UI.

## Security considerations

| Concern | Implementation |
|---|---|
| CSRF/replay | Random state/nonce, ten-minute expiry and one-time conditional state claim. |
| Token forgery | RS256 verification against Telegram JWKS plus issuer, audience, expiration and nonce checks. |
| Secret exposure | Client secret remains only in backend environment variables. |
| Account takeover | Existing Telegram identity is merged only after the signed-in website user explicitly starts the flow; legacy chat linking returns conflict instead of moving a chat. |

## Dependencies added

| Package | Why |
|---|---|
| `web-token/jwt-framework` | Verify Telegram OIDC JWS tokens and JWKS signatures. |

## How to verify manually

1. Ensure the BotFather redirect URL exactly matches `TELEGRAM_OIDC_REDIRECT_URI` and is publicly reachable over HTTPS.
2. Log in to the website, open the Radar page and select **«Подтвердить Telegram»**.
3. Complete Telegram Login in the popup. Expected: the popup closes, the page says that the Telegram account is confirmed, and favorites/alerts are shared.
4. If the same game exists on both accounts, confirm the site target price is retained and scopes from both accounts appear in the favorite settings.
5. Use the bot’s legacy `/start CODE` only to bind the notification chat; it must reject a chat already bound elsewhere.

## Verification status

- [x] Backend tests pass: 28 tests, 109 assertions.
- [x] Frontend lint and production build pass (one unrelated pre-existing lint warning remains).
- [x] Test database applied all migrations from scratch.
- [ ] One production-like live Telegram OIDC callback still needs a human test with the real BotFather application.

## Developer note

The OIDC identity (`sub`) and a Telegram Bot API `chat_id` are different values. The first confirms who owns the website account; the second is still required for the bot to send alerts. The legacy code link remains intentionally until bot-side OIDC access is proven in a live callback.
