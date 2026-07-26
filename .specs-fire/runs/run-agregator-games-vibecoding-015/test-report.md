# Test report: telegram-menu-navigation

## Result

- Command: `docker compose run --rm -v "${PWD}\\bot:/app" radar-bot python -m unittest discover -s tests -v`
- Result: 29 passed, 0 failed.

## Acceptance criteria

- [x] `/start` displays a reply menu with search, favorites, alerts and help.
- [x] Search, favorites and alerts are reachable with buttons; slash commands remain optional fallbacks.
- [x] Existing contextual inline keyboards are unchanged.
- [x] Returning to the main menu does not clear the FSM; starting a brand-new search intentionally clears the old flow.
- [x] Menu scenarios have isolated UI and handler tests.

## Notes

No real Telegram API was called. Tests use mocked messages and API client calls.
