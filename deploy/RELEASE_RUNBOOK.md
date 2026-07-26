# Release runbook

This procedure is intentionally manual. Commands are run from `/opt/gpa` on the
VPS unless stated otherwise.

## 1. Preflight

Record the revision being released and a known-good rollback revision. Confirm
that the GitHub Pipeline is green for Laravel/PostgreSQL, frontend, bot and
Compose jobs. Check that the production environment has an approver.

On the server:

```bash
cd /opt/gpa
docker compose config --quiet
docker compose ps
df -h
mkdir -p backups
```

Do not continue if PostgreSQL is unhealthy, disk space is low, or scheduler and
queue-worker are already crash-looping.

## 2. PostgreSQL backup

Create a timestamped custom-format backup before syncing code or running a
migration:

```bash
cd /opt/gpa
stamp="$(date -u +%Y%m%dT%H%M%SZ)"
docker compose exec -T db sh -c 'pg_dump -U "$POSTGRES_USER" -d "$POSTGRES_DB" -Fc' > "backups/gpa-${stamp}.dump"
test -s "backups/gpa-${stamp}.dump"
docker compose exec -T db sh -c 'pg_restore --list' < "backups/gpa-${stamp}.dump" | head
```

Copy the backup off the VPS according to the project retention policy. Record
its path next to the release revision.

## 3. Deploy and migrate

In GitHub Actions run Pipeline manually for the verified revision with
`deploy_production=true`, then approve environment `production`. The workflow
syncs the revision and starts Compose. Only the `backend` service has
`RUN_MIGRATIONS=true`; scheduler and queue-worker wait until backend is healthy.

Do not run `php artisan migrate` concurrently in another shell.

## 4. Smoke checks

```bash
cd /opt/gpa
docker compose ps
curl -fsS http://127.0.0.1/api/health
docker compose exec -T backend php artisan migrate:status
docker compose exec -T backend php artisan schedule:list
docker compose exec -T backend php artisan ops:snapshot --hours=24
docker compose exec -T backend php artisan queue:failed
docker compose logs --since=10m --tail=200 backend scheduler queue-worker radar-bot
```

Confirm all four application services are running, `/api/health` reports an
available database, the two expected Laravel schedule entries exist, and no new
failed jobs or repeated source/Telegram errors appeared. Perform one website
search and one private-chat bot command without invoking real alert delivery as
a test fixture.

## 5. Monitoring after release

For at least one full refresh interval monitor:

```bash
docker compose logs --since=30m -f scheduler queue-worker backend
docker compose exec -T backend php artisan queue:failed
```

Review the structured `ops:snapshot` output for source freshness and failures,
processed games, created alert events and sent/failed deliveries. Escalate if
freshness stops advancing, failed jobs grow, or a service restarts repeatedly.

## 6. Code rollback

If migrations are backward-compatible, revert the faulty release on `main` or
`master` to the recorded known-good code, let CI pass, then rerun the manual
Pipeline with `deploy_production=true`. Approve the production environment again
and repeat all smoke checks.

Do not use `php artisan migrate:rollback` blindly. Some migrations remove data
and their `down()` method can recreate structure only.

## 7. Database restore

Use restore only when the release changed data incompatibly or a destructive
migration must be reversed. This discards database writes made after the backup.
Obtain explicit incident approval and announce the maintenance window first.

```bash
cd /opt/gpa
backup="backups/gpa-YYYYMMDDTHHMMSSZ.dump"
test -s "$backup"
docker compose stop frontend caddy tunnel radar-bot scheduler queue-worker backend
docker compose exec -T db sh -c 'dropdb --if-exists -U "$POSTGRES_USER" "$POSTGRES_DB" && createdb -U "$POSTGRES_USER" "$POSTGRES_DB"'
docker compose exec -T db sh -c 'pg_restore -U "$POSTGRES_USER" -d "$POSTGRES_DB" --exit-on-error' < "$backup"
docker compose up -d backend
docker compose up -d scheduler queue-worker frontend caddy tunnel radar-bot
```

Run the smoke checks again and record the restored backup, rollback revision,
incident owner and timestamps.
