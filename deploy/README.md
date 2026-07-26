# Production deployment

Игроскан разворачивается корневым `docker-compose.yml`: PostgreSQL, Laravel API,
frontend, Laravel scheduler, queue worker, Telegram bot, Caddy и Cloudflare
Tunnel. Старый Python/SQLite override больше не используется.

## One-time VPS setup

На Ubuntu/Debian выполните `deploy/setup-server.sh`, создайте пользователя
`deploy`, добавьте его в группу `docker` и выдайте ему доступ к `/opt/gpa`.
Откройте SSH и порты 80/443.

## GitHub configuration

Создайте environment `production` с required reviewers. Добавьте secrets:

| Secret | Required | Default |
|---|---:|---|
| `DEPLOY_HOST` | yes | — |
| `DEPLOY_USER` | yes | — |
| `DEPLOY_SSH_KEY` | yes | — |
| `DEPLOY_PATH` | no | `/opt/gpa` |
| `DEPLOY_PORT` | no | `22` |
| `APP_KEY` | recommended | generated on first deploy |
| `POSTGRES_PASSWORD` | recommended | generated on first deploy |

Application secrets such as `TELEGRAM_BOT_TOKEN`, `RADAR_SERVICE_TOKEN` and
Telegram OIDC credentials live only in `/opt/gpa/.env`; rsync never overwrites
that file.

## CI and manual deploy

Pushes and pull requests run Laravel/PostgreSQL tests, frontend lint/build, bot
unit tests and Compose validation/build. They never deploy automatically.

To deploy, open GitHub Actions → Pipeline → Run workflow on verified `main` or
`master`, enable `deploy_production`, then pass the `production` approval gate.

Before every production release follow
[`RELEASE_RUNBOOK.md`](RELEASE_RUNBOOK.md). It contains backup, migration,
smoke-check, monitoring and rollback/restore procedures.
