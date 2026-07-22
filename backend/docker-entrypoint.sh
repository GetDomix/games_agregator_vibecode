#!/usr/bin/env bash
set -euo pipefail

cd /var/www/html

if [ -z "${APP_KEY:-}" ] || [ "$APP_KEY" = "base64:" ]; then
  if [ ! -f .env ]; then
    cp .env.example .env 2>/dev/null || true
  fi
  # Only generate if still empty in runtime env
  if ! grep -qE '^APP_KEY=base64:.+' .env 2>/dev/null && [ -z "${APP_KEY:-}" ]; then
    php artisan key:generate --force --no-interaction || true
  fi
fi

echo "Waiting for PostgreSQL at ${DB_HOST:-db}:${DB_PORT:-5432}..."
for i in $(seq 1 60); do
  if php -r "
    try {
      new PDO(
        'pgsql:host=' . getenv('DB_HOST') . ';port=' . (getenv('DB_PORT') ?: '5432') . ';dbname=' . getenv('DB_DATABASE'),
        getenv('DB_USERNAME'),
        getenv('DB_PASSWORD')
      );
      exit(0);
    } catch (Throwable \$e) {
      exit(1);
    }
  "; then
    echo "PostgreSQL is up"
    break
  fi
  if [ "$i" -eq 60 ]; then
    echo "PostgreSQL not reachable" >&2
    exit 1
  fi
  sleep 2
done

php artisan migrate --force --no-interaction
php artisan config:cache || true
php artisan route:cache || true

exec "$@"
