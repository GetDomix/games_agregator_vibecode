# Production release runbook

Документ применяется только после отдельного разрешения на deployment. Сейчас
он является проверяемой заготовкой; перечисленные команды не запускаются
автоматически.

## 1. Approval и preflight

Зафиксировать release revision и rollback revision. Проверить зелёный CI,
успешный `composer audit --locked --no-interaction`, наличие ответственного за
релиз и окна наблюдения.

На будущем сервере:

```bash
cd /opt/gpa
test -f deploy/.env.production
chmod 600 deploy/.env.production
./deploy/preflight-production.sh deploy/.env.production
```

Остановиться при любой ошибке. Не подставлять wildcard и временный HTTP URL
ради прохождения проверки.

## 2. Обязательный backup перед миграцией

```bash
cd /opt/gpa
production_env=deploy/.env.production
export PRODUCTION_ENV_FILE="$production_env"
compose=(docker compose --env-file "$production_env" -f docker-compose.yml -f compose.production.yml)
mkdir -p backups
chmod 700 backups
umask 077
stamp="$(date -u +%Y%m%dT%H%M%SZ)"
backup="backups/igroscan-${stamp}.dump"
"${compose[@]}" exec -T db sh -c 'pg_dump -U "$POSTGRES_USER" -d "$POSTGRES_DB" -Fc' > "$backup"
test -s "$backup"
"${compose[@]}" exec -T db sh -c 'pg_restore --list' < "$backup" >/dev/null
sha256sum "$backup" > "${backup}.sha256"
```

До миграции копия должна быть зашифрованно передана во внешнее backup-хранилище
согласно `BACKUP_POLICY.md`. Файл на том же VPS не считается полноценным
backup.

## 3. Будущий ручной запуск

Только после approval:

```bash
"${compose[@]}" up -d --build --remove-orphans
"${compose[@]}" ps
```

Только backend имеет `RUN_MIGRATIONS=true`; параллельно запускать
`php artisan migrate` запрещено. Profiles `manual-tunnel` и `telegram` не
включать без отдельной проверки их секретов и сетевой необходимости.

## 4. HTTPS smoke checks

```bash
domain="$(awk -F= '$1 == "APP_DOMAIN" { print substr($0, index($0, "=") + 1) }' deploy/.env.production)"
curl --fail --silent --show-error "https://${domain}/api/health"
"${compose[@]}" exec -T backend php artisan migrate:status
"${compose[@]}" exec -T backend php artisan schedule:list
"${compose[@]}" exec -T backend php artisan queue:failed
"${compose[@]}" logs --since=10m --tail=200 backend scheduler queue-worker caddy
```

Дополнительно проверить с внешней машины:

- HTTP перенаправляется на HTTPS;
- сертификат валиден без `-k`;
- `5432` и `8080` недоступны извне;
- неизвестный Host/IP не отдаёт приложение;
- cookie содержит `Secure`, `HttpOnly`, `SameSite=Lax`;
- чужой `Origin` не получает CORS allow headers.

## 5. Monitoring и rollback

Наблюдать как минимум один полный цикл обновления цен. При ошибке откатить
только код на заранее зафиксированную revision, если миграции
backward-compatible. Не выполнять `migrate:rollback` вслепую.

Восстановление БД уничтожает записи после выбранной точки. Оно требует
отдельного incident approval, maintenance window и подтверждённого off-host
backup. Процедура восстановления и регулярность её тестирования описаны в
`BACKUP_POLICY.md`.
