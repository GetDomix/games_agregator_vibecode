#!/usr/bin/env bash
set -euo pipefail
cd /opt/gpa

PG="$(grep -E '^POSTGRES_PASSWORD=' .env | cut -d= -f2- | tr -d '\r')"
if [ -z "$PG" ]; then
  PG="$(openssl rand -hex 16)"
  echo "POSTGRES_PASSWORD=${PG}" >> .env
fi

echo "Testing PDO..."
if docker exec gpa-backend-1 php -r "
try {
  new PDO('pgsql:host=db;port=5432;dbname=gpa', 'gpa', getenv('DB_PASSWORD'));
  exit(0);
} catch (Throwable \$e) {
  fwrite(STDERR, \$e->getMessage() . PHP_EOL);
  exit(1);
}
" 2>/tmp/pdo_err; then
  echo "PDO already OK"
else
  echo "PDO failed: $(cat /tmp/pdo_err 2>/dev/null || true)"
  echo "Resetting Postgres volume to match .env password..."
  docker compose stop backend frontend caddy tunnel || true
  docker compose stop db || true
  docker compose rm -f backend db || true
  docker volume rm gpa_gpa_pgdata 2>/dev/null || true
  docker compose up -d db
  echo "waiting for db..."
  for i in $(seq 1 30); do
    if docker exec gpa-db-1 pg_isready -U gpa -d gpa >/dev/null 2>&1; then
      break
    fi
    sleep 1
  done
  docker compose up -d --build backend frontend caddy tunnel
fi

# Ensure backend is serving
echo "waiting for backend health..."
ok=0
for i in $(seq 1 40); do
  if curl -fsS http://127.0.0.1:8080/api/health >/dev/null 2>&1; then
    ok=1
    break
  fi
  # if backend crashloop, show logs
  sleep 3
done

if [ "$ok" != 1 ]; then
  echo "backend still down — full recreate without wiping volume if possible"
  docker compose up -d --force-recreate backend
  sleep 10
fi

curl -fsS http://127.0.0.1:8080/api/health || true
echo
curl -fsS http://127.0.0.1/api/health || true
echo
curl -fsS -o /dev/null -w "prices=%{http_code}\n" -G "http://127.0.0.1/api/prices" --data-urlencode "q=hades" || true
docker compose ps
docker logs gpa-backend-1 2>&1 | tail -25
echo "tunnel:"; docker logs gpa-tunnel-1 2>&1 | grep -oE 'https://[a-zA-Z0-9.-]+\.trycloudflare\.com' | tail -1 || true
