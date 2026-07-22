#!/usr/bin/env bash
set -euo pipefail

cd /var/www/html

# Compose may map POSTGRES_PASSWORD via env_file; Laravel expects DB_*
if [ -z "${DB_PASSWORD:-}" ] && [ -n "${POSTGRES_PASSWORD:-}" ]; then
  export DB_PASSWORD="$POSTGRES_PASSWORD"
fi
if [ -z "${DB_USERNAME:-}" ] && [ -n "${POSTGRES_USER:-}" ]; then
  export DB_USERNAME="$POSTGRES_USER"
fi
if [ -z "${DB_DATABASE:-}" ] && [ -n "${POSTGRES_DB:-}" ]; then
  export DB_DATABASE="$POSTGRES_DB"
fi

if [ -z "${APP_KEY:-}" ] || [ "$APP_KEY" = "base64:" ]; then
  if [ ! -f .env ]; then
    cp .env.example .env 2>/dev/null || true
  fi
  # Only generate if still empty in runtime env
  if ! grep -qE '^APP_KEY=base64:.+' .env 2>/dev/null && [ -z "${APP_KEY:-}" ]; then
    php artisan key:generate --force --no-interaction || true
  fi
fi

echo "Waiting for PostgreSQL at ${DB_HOST:-db}:${DB_PORT:-5432} (user=${DB_USERNAME:-?} db=${DB_DATABASE:-?})..."
LAST_ERR=""
for i in $(seq 1 60); do
  if LAST_ERR=$(php -r "
    try {
      new PDO(
        'pgsql:host=' . getenv('DB_HOST') . ';port=' . (getenv('DB_PORT') ?: '5432') . ';dbname=' . getenv('DB_DATABASE'),
        getenv('DB_USERNAME'),
        getenv('DB_PASSWORD')
      );
      exit(0);
    } catch (Throwable \$e) {
      fwrite(STDERR, \$e->getMessage());
      exit(1);
    }
  " 2>&1); then
    echo "PostgreSQL is up"
    break
  fi
  if [ "$i" -eq 60 ]; then
    echo "PostgreSQL not reachable after 60 tries: ${LAST_ERR}" >&2
    exit 1
  fi
  if [ $((i % 10)) -eq 0 ]; then
    echo "still waiting ($i/60): ${LAST_ERR}"
  fi
  sleep 2
done

php artisan migrate --force --no-interaction
php artisan config:cache || true
php artisan route:cache || true

exec "$@"
