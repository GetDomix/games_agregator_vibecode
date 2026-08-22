#!/usr/bin/env bash
set -euo pipefail
cd /opt/gpa

systemctl stop nginx 2>/dev/null || true
systemctl disable nginx 2>/dev/null || true
docker rm -f gpa-web-1 2>/dev/null || true

docker compose down --remove-orphans || true
# Drop volume so Postgres password matches current .env (was rotated earlier)
docker volume rm gpa_gpa_pgdata 2>/dev/null || true
echo "volumes:"; docker volume ls | grep -i gpa || echo none

test -f .env || touch .env
grep -q '^POSTGRES_PASSWORD=' .env || echo "POSTGRES_PASSWORD=$(openssl rand -hex 16)" >> .env
grep -q '^APP_KEY=base64:' .env || echo "APP_KEY=base64:$(openssl rand -base64 32 | tr -d '\n')" >> .env
grep -q '^POSTGRES_DB=' .env || echo 'POSTGRES_DB=gpa' >> .env
grep -q '^POSTGRES_USER=' .env || echo 'POSTGRES_USER=gpa' >> .env
grep -q '^FRONTEND_PORT=' .env || echo 'FRONTEND_PORT=80' >> .env
grep -q '^BACKEND_PORT=' .env || echo 'BACKEND_PORT=8080' >> .env

if ss -tlnp 2>/dev/null | grep -qE ':80 '; then
  echo "freeing port 80"
  fuser -k 80/tcp 2>/dev/null || true
  sleep 1
fi

docker compose up -d --build --remove-orphans

echo "waiting for backend health..."
ok=0
for i in $(seq 1 45); do
  if curl -fsS http://127.0.0.1:8080/api/health >/dev/null 2>&1; then
    echo "backend OK try $i"
    ok=1
    break
  fi
  sleep 3
done

if [ "$ok" != 1 ]; then
  echo "BACKEND FAIL" >&2
  docker logs gpa-backend-1 2>&1 | tail -40 >&2 || true
  docker logs gpa-db-1 2>&1 | tail -20 >&2 || true
  exit 1
fi

echo -n "backend: "; curl -fsS http://127.0.0.1:8080/api/health; echo
echo -n "proxy: "; curl -fsS http://127.0.0.1/api/health; echo
curl -fsS -o /dev/null -w "home=%{http_code}\n" http://127.0.0.1/
docker compose ps
echo "DONE"
