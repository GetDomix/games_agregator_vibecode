#!/usr/bin/env bash
set -euo pipefail

project_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
preflight="${project_root}/deploy/preflight-production.sh"
fixture_dir="$(mktemp -d)"
trap 'rm -rf "${fixture_dir}"' EXIT

valid_env="${fixture_dir}/production.env"
cat > "${valid_env}" <<'ENV'
APP_DOMAIN=games.example.test
ACME_EMAIL=ops@example.test
APP_ENV=production
APP_DEBUG=false
APP_KEY=base64:MDEyMzQ1Njc4OWFiY2RlZjAxMjM0NTY3ODlhYmNkZWY=
POSTGRES_DB=igroscan
POSTGRES_USER=igroscan
POSTGRES_PASSWORD=test-only-0123456789abcdef0123456789
CORS_ORIGINS=https://games.example.test
CORS_SUPPORTS_CREDENTIALS=true
SANCTUM_STATEFUL_DOMAINS=games.example.test
TRUSTED_PROXIES=10.0.0.0/8,172.16.0.0/12,192.168.0.0/16
SESSION_SECURE_COOKIE=true
SESSION_HTTP_ONLY=true
SESSION_SAME_SITE=lax
SESSION_ENCRYPT=true
ENV
chmod 600 "${valid_env}"

if [[ "${PREFLIGHT_TEST_COMPOSE:-0}" == "1" ]]; then
    "${preflight}" "${valid_env}"
else
    PREFLIGHT_SKIP_COMPOSE=1 "${preflight}" "${valid_env}"
fi

expect_rejected() {
    local name="$1"
    local key="$2"
    local value="$3"
    local candidate="${fixture_dir}/${name}.env"

    cp "${valid_env}" "${candidate}"
    sed -i.bak "s|^${key}=.*|${key}=${value}|" "${candidate}"
    rm -f "${candidate}.bak"

    if PREFLIGHT_SKIP_COMPOSE=1 "${preflight}" "${candidate}" >/dev/null 2>&1; then
        echo "expected rejection: ${name}" >&2
        exit 1
    fi
}

expect_rejected missing-domain APP_DOMAIN ''
expect_rejected local-domain APP_DOMAIN localhost
expect_rejected invalid-acme-email ACME_EMAIL not-an-email
expect_rejected wrong-environment APP_ENV local
expect_rejected debug-enabled APP_DEBUG true
expect_rejected missing-app-key APP_KEY ''
expect_rejected weak-database-password POSTGRES_PASSWORD change-me
expect_rejected wildcard-cors CORS_ORIGINS '*'
expect_rejected plaintext-cors CORS_ORIGINS http://games.example.test
expect_rejected credentials-disabled CORS_SUPPORTS_CREDENTIALS false
expect_rejected wrong-stateful-domain SANCTUM_STATEFUL_DOMAINS other.example.test
expect_rejected wildcard-proxy TRUSTED_PROXIES '*'
expect_rejected insecure-cookie SESSION_SECURE_COOKIE false
expect_rejected script-readable-cookie SESSION_HTTP_ONLY false
expect_rejected cross-site-cookie SESSION_SAME_SITE none
expect_rejected unencrypted-session SESSION_ENCRYPT false

echo "production preflight valid fixture: ok"
