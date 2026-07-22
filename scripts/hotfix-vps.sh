#!/usr/bin/env bash
set -euo pipefail
cd /opt/gpa

# Empty shell overrides destroy .env interpolation
unset APP_KEY POSTGRES_PASSWORD POSTGRES_USER POSTGRES_DB 2>/dev/null || true

# Ensure required keys in .env
if ! grep -qE '^APP_KEY=base64:.+' .env 2>/dev/null; then
  echo "APP_KEY=base64:$(openssl rand -base64 32 | tr -d '\n')" >> .env
fi
if ! grep -qE '^POSTGRES_PASSWORD=.+' .env; then
  echo "POSTGRES_PASSWORD=$(openssl rand -hex 16)" >> .env
fi
grep -q '^POSTGRES_DB=' .env || echo 'POSTGRES_DB=gpa' >> .env
grep -q '^POSTGRES_USER=' .env || echo 'POSTGRES_USER=gpa' >> .env

HOST_IP="$(hostname -I | awk '{print $1}')"
HTTPS_HOST="gpa.${HOST_IP}.sslip.io"
HTTPS_URL="https://${HTTPS_HOST}"
sed -i "s|^APP_URL=.*|APP_URL=${HTTPS_URL}|" .env || true
sed -i "s|^FRONTEND_URL=.*|FRONTEND_URL=${HTTPS_URL}|" .env || true
grep -q '^APP_URL=' .env || echo "APP_URL=${HTTPS_URL}" >> .env
grep -q '^FRONTEND_URL=' .env || echo "FRONTEND_URL=${HTTPS_URL}" >> .env

echo "=== recreate stack with stable .env (no empty shell secrets) ==="
# Reset PG volume so password matches current .env (crashloop from GHA empty env)
docker compose stop backend 2>/dev/null || true
docker compose rm -f backend 2>/dev/null || true
docker compose stop db 2>/dev/null || true
docker compose rm -f db 2>/dev/null || true
docker volume rm gpa_gpa_pgdata 2>/dev/null || true

docker compose up -d --build --remove-orphans

echo "waiting for health..."
ok=0
for i in $(seq 1 50); do
  if curl -fsS http://127.0.0.1:8080/api/health >/dev/null 2>&1; then
    ok=1
    break
  fi
  sleep 3
done
if [ "$ok" != 1 ]; then
  echo "FAIL backend" >&2
  docker logs gpa-backend-1 2>&1 | tail -40 >&2
  docker logs gpa-db-1 2>&1 | tail -20 >&2
  exit 1
fi

curl -fsS http://127.0.0.1:8080/api/health; echo
curl -fsS http://127.0.0.1/api/health; echo
curl -fsSk "${HTTPS_URL}/api/health"; echo
curl -fsS -o /dev/null -w "prices=%{http_code} t=%{time_total}\n" -G "http://127.0.0.1/api/prices" --data-urlencode "q=hades"
docker compose ps
echo "tunnel URL:"
docker logs gpa-tunnel-1 2>&1 | grep -oE 'https://[a-zA-Z0-9.-]+\.trycloudflare\.com' | tail -1 || true
echo DONE
