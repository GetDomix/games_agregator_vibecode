---
work_item: telegram-identity-merge
intent: unified-price-watchlist-mvp
created: 2026-07-25T16:25:00Z
mode: validate
checkpoint_1: approved
---

# Design: Telegram-идентификация и объединение аккаунтов

## Summary

Telegram becomes an external identity through official OIDC Authorization Code Flow with PKCE. A server-side transaction merges Telegram-owned and website-owned favorites/settings deterministically.

## Key Decisions

| Decision | Choice | Rationale |
|---|---|---|
| Identity storage | `external_identities` with unique provider subject | No synthetic email; one Telegram ID has one owner. |
| OIDC | auth code + PKCE/state/nonce + JWKS claim validation | Official Telegram flow and replay/CSRF protection. |
| Merge | Transactional, idempotent, reportable | Prevents duplicate favorites and silent reassignment. |
| Conflicts | Website target wins, otherwise Telegram target | Matches approved product rule. |
| Legacy | Keep code link during migration only | Avoids breaking existing bot users. |

## Technical Approach

```text
begin -> server state/PKCE -> Telegram OIDC -> callback
 -> token exchange -> JWKS validate -> external identity
 -> atomic favorite/alert scope merge -> report
```

## Risks & Mitigations

| Risk | Mitigation |
|---|---|
| Token replay / CSRF | One-time expiring state, PKCE and nonce. |
| Wrong issuer/audience | JWKS signature plus iss/aud/exp checks. |
| Lost scopes/targets | Merge tests, unique constraints, website precedence. |
| Existing links | Migration/backward compatibility before removal. |

## Implementation Checklist

- [ ] External identity and OIDC transaction schema.
- [ ] OIDC begin/callback with secure validation.
- [ ] Telegram profile creation and merge service.
- [ ] Conflict report API and legacy compatibility.
- [ ] Security and merge feature tests.

---
*Checkpoint 1 approved.*
