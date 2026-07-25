---
run: run-agregator-games-vibecoding-005
work_item: telegram-identity-merge
generated: 2026-07-25
---

# Test Report: Telegram identity merge

## Results

- Backend: `php artisan test` — **28 passed**, 109 assertions.
- Frontend: `npm run build` — passed.
- Frontend lint: completed; one pre-existing warning in `frontend/src/brand.tsx` about Fast Refresh exports.

## Acceptance validation

- [x] Authorization Code + PKCE transaction stores state, nonce and verifier for ten minutes.
- [x] Callback exchanges the code server-side and verifies the Telegram JWS against JWKS, issuer, audience, expiry and nonce.
- [x] An external identity has a unique Telegram subject and may own an account without email.
- [x] Merge preserves unique favorites, website target price precedence and combines alert scopes.
- [x] Existing bot chat is transferred during an explicit account merge; a separate link cannot silently reassign it.
- [x] OIDC secret and verifier stay server-side; the popup result uses `postMessage`, not a token in the URL.

## Notes

The complete live callback cannot be exercised locally without a real Telegram authorization code. Automated tests cover PKCE transaction creation, merge conflict behavior, email-less external accounts and bot-link conflicts; manual verification with the configured BotFather client remains required before release.
