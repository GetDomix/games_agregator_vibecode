#!/usr/bin/env bash
set -euo pipefail
cd /opt/gpa

for svc in nginx apache2 httpd; do
  systemctl stop "$svc" 2>/dev/null || true
  systemctl disable "$svc" 2>/dev/null || true
done
docker rm -f gpa-web-1 2>/dev/null || true

docker compose down --remove-orphans || true
for p in 80 443; do
  fuser -k "${p}/tcp" 2>/dev/null || true
done
sleep 1

HOST_IP="$(hostname -I | awk '{print $1}')"
HTTPS_HOST="gpa.${HOST_IP}.sslip.io"
HTTPS_URL="https://${HTTPS_HOST}"

if [ ! -f .env ]; then
  APP_KEY_VAL="base64:$(openssl rand -base64 32 | tr -d '\n')"
  PG_PASS="$(openssl rand -hex 16)"
  cat > .env <<EOF
APP_ENV=production
APP_DEBUG=false
APP_KEY=${APP_KEY_VAL}
APP_URL=${HTTPS_URL}
FRONTEND_URL=${HTTPS_URL}
POSTGRES_DB=gpa
POSTGRES_USER=gpa
POSTGRES_PASSWORD=${PG_PASS}
BACKEND_PORT=8080
CORS_ORIGINS=*
SANCTUM_STATEFUL_DOMAINS=${HTTPS_HOST},${HOST_IP},localhost,127.0.0.1
EOF
  chmod 600 .env
else
  # Keep password; upgrade URLs to HTTPS sslip host
  sed -i "s|^APP_URL=.*|APP_URL=${HTTPS_URL}|" .env || echo "APP_URL=${HTTPS_URL}" >> .env
  sed -i "s|^FRONTEND_URL=.*|FRONTEND_URL=${HTTPS_URL}|" .env || echo "FRONTEND_URL=${HTTPS_URL}" >> .env
  if ! grep -q '^SANCTUM_STATEFUL_DOMAINS=' .env; then
    echo "SANCTUM_STATEFUL_DOMAINS=${HTTPS_HOST},${HOST_IP},localhost,127.0.0.1" >> .env
  fi
fi

# Never let empty shell secrets override /opt/gpa/.env
if [ -z "${APP_KEY:-}" ]; then unset APP_KEY || true; fi
if [ -z "${POSTGRES_PASSWORD:-}" ]; then unset POSTGRES_PASSWORD || true; fi

docker compose up -d --build --remove-orphans

echo "waiting for health..."
ok=0
for i in $(seq 1 50); do
  if curl -fsS http://127.0.0.1/api/health >/dev/null 2>&1; then
    ok=1
    break
  fi
  sleep 3
done
if [ "$ok" != 1 ]; then
  echo "health failed" >&2
  docker compose ps >&2
  docker logs --tail 40 gpa-caddy-1 >&2 || true
  docker logs --tail 40 gpa-backend-1 >&2 || true
  exit 1
fi

echo -n "http: "; curl -fsS http://127.0.0.1/api/health; echo
echo -n "https: "
curl -fsSk "${HTTPS_URL}/api/health" || echo "(cert still provisioning)"
echo
echo -n "prices: "
curl -fsS -o /dev/null -w "%{http_code} %{time_total}s\n" -G "http://127.0.0.1/api/prices" --data-urlencode "q=cyberpunk"
echo "--- cloudflare quick tunnel URL (if any) ---"
sleep 3
docker logs gpa-tunnel-1 2>&1 | grep -oE 'https://[a-zA-Z0-9.-]+\.trycloudflare\.com' | tail -3 || true
docker compose ps
echo "DONE"
echo "HTTPS: ${HTTPS_URL}"
echo "HTTP:  http://${HOST_IP}"
