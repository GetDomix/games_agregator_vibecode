# Production configuration (prepared, not deployed)

Этот каталог содержит строгую production-заготовку. Её добавление в репозиторий
не запускает контейнеры, не получает сертификаты, не меняет DNS и не создаёт
production-секреты.

## Что подготовлено

- `compose.production.yml` убирает host-порты PostgreSQL и Laravel backend;
- `Caddyfile.production` принимает публичный трафик только по утверждённому
  HTTPS hostname и добавляет защитные заголовки;
- `.env.production.example` перечисляет необходимые параметры без реальных
  секретов и домена;
- `preflight-production.sh` запрещает wildcard CORS/proxy, debug, HTTP origin,
  слабые ключи и небезопасные session cookies;
- `BACKUP_POLICY.md` задаёт резервное копирование и обязательную проверку
  восстановления.

Публичными в будущем могут быть только порты Caddy `80/443`. PostgreSQL и
backend доступны лишь внутри Docker network. В базовом локальном Compose их
host-порты дополнительно привязаны к `127.0.0.1`.

Cloudflare quick tunnel и Telegram bot помещены в отдельные Compose profiles и
не включаются строгим production overlay автоматически.

## Подготовка env (только после разрешения release)

```bash
cp deploy/.env.production.example deploy/.env.production
chmod 600 deploy/.env.production
```

Затем вручную заполняются `APP_DOMAIN`, `ACME_EMAIL`, `APP_KEY`,
`POSTGRES_PASSWORD`, оба точных hostname-поля CORS/Sanctum, `ADMIN_EMAILS` и
нужные интеграционные секреты. Значения не должны попадать в Git, CI logs или
чат.

Рекомендуемые команды генерации выполняются непосредственно на будущем
сервере:

```bash
printf 'base64:%s\n' "$(openssl rand -base64 32 | tr -d '\n')"
openssl rand -base64 48
```

Первая строка подходит для `APP_KEY`, вторая — для отдельного пароля БД или
service token. Один и тот же результат нельзя переиспользовать для разных
секретов.

## Проверка без запуска

```bash
./deploy/preflight-production.sh deploy/.env.production
```

Preflight только валидирует env и результат `docker compose config`. Он не
выполняет `up`, не подключается к DNS/ACME и не меняет БД.

Будущая Compose-команда описана в `RELEASE_RUNBOOK.md`, но до отдельного
разрешения её выполнять нельзя.

## Deployment gate

GitHub job deployment отключён по умолчанию. Даже ручной workflow с включённым
input не запустит его, пока в настройках репозитория отдельно не создана
variable `PRODUCTION_DEPLOYMENTS_ENABLED=true` и не пройден approval environment
`production`. Обычный push в `master` ничего не разворачивает.
