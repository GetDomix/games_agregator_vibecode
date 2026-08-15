#!/usr/bin/env bash
set -euo pipefail

env_file="${1:-deploy/.env.production}"
project_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

if [[ ! -f "${env_file}" ]]; then
    echo "production env file not found: ${env_file}" >&2
    exit 1
fi

read_value() {
    local key="$1"
    local count

    count="$(awk -v key="${key}" 'index($0, key "=") == 1 { count++ } END { print count + 0 }' "${env_file}")"
    if [[ "${count}" -ne 1 ]]; then
        echo "${key} must occur exactly once in ${env_file}" >&2
        exit 1
    fi

    awk -v key="${key}" 'index($0, key "=") == 1 { print substr($0, length(key) + 2) }' "${env_file}"
}

require_exact() {
    local key="$1"
    local expected="$2"
    local actual

    actual="$(read_value "${key}")"
    if [[ "${actual}" != "${expected}" ]]; then
        echo "${key} must be ${expected}" >&2
        exit 1
    fi
}

domain="$(read_value APP_DOMAIN)"
if [[ -z "${domain}" || "${domain}" == "localhost" || "${domain}" == *"://"* || "${domain}" == */* ]]; then
    echo "APP_DOMAIN must be a non-local hostname without scheme or path" >&2
    exit 1
fi
if [[ ! "${domain}" =~ ^([A-Za-z0-9]([A-Za-z0-9-]{0,61}[A-Za-z0-9])?\.)+[A-Za-z]{2,63}$ ]]; then
    echo "APP_DOMAIN is not a valid DNS hostname" >&2
    exit 1
fi

acme_email="$(read_value ACME_EMAIL)"
if [[ ! "${acme_email}" =~ ^[^[:space:]@]+@[^[:space:]@]+\.[^[:space:]@]+$ ]]; then
    echo "ACME_EMAIL must be a valid email address" >&2
    exit 1
fi

require_exact APP_ENV production
require_exact APP_DEBUG false

app_key="$(read_value APP_KEY)"
if [[ ! "${app_key}" =~ ^base64:[A-Za-z0-9+/]{43}=$ ]]; then
    echo "APP_KEY must be a Laravel base64 key generated from 32 random bytes" >&2
    exit 1
fi

database_password="$(read_value POSTGRES_PASSWORD)"
if [[ "${#database_password}" -lt 32 || "${database_password}" == "gpa_secret_change_me" || "${database_password}" == "change-me" ]]; then
    echo "POSTGRES_PASSWORD must contain at least 32 non-placeholder characters" >&2
    exit 1
fi

require_exact CORS_ORIGINS "https://${domain}"
require_exact CORS_SUPPORTS_CREDENTIALS true
require_exact SANCTUM_STATEFUL_DOMAINS "${domain}"

trusted_proxies="$(read_value TRUSTED_PROXIES)"
if [[ -z "${trusted_proxies}" || "${trusted_proxies}" == *"*"* ]]; then
    echo "TRUSTED_PROXIES must contain explicit private proxy networks, never *" >&2
    exit 1
fi
IFS=',' read -r -a proxy_items <<< "${trusted_proxies}"
for proxy in "${proxy_items[@]}"; do
    case "${proxy}" in
        10.0.0.0/8|172.16.0.0/12|192.168.0.0/16|127.0.0.1|::1) ;;
        *)
            echo "TRUSTED_PROXIES contains an unsupported network" >&2
            exit 1
            ;;
    esac
done

require_exact SESSION_SECURE_COOKIE true
require_exact SESSION_HTTP_ONLY true
require_exact SESSION_SAME_SITE lax
require_exact SESSION_ENCRYPT true

if [[ "${PREFLIGHT_SKIP_COMPOSE:-0}" != "1" ]]; then
    if ! command -v docker >/dev/null 2>&1; then
        echo "docker is required for production Compose validation" >&2
        exit 1
    fi

    env_mode="$(stat -f '%Lp' "${env_file}" 2>/dev/null || stat -c '%a' "${env_file}")"
    if (( (8#${env_mode}) & 8#077 )); then
        echo "${env_file} must not be readable or writable by group/others (use chmod 600)" >&2
        exit 1
    fi

    export PRODUCTION_ENV_FILE
    PRODUCTION_ENV_FILE="$(cd "$(dirname "${env_file}")" && pwd)/$(basename "${env_file}")"
    compose=(docker compose --env-file "${env_file}" -f "${project_root}/docker-compose.yml" -f "${project_root}/compose.production.yml")
    "${compose[@]}" config --quiet

    rendered="$(${compose[@]} config)"
    for service in db backend; do
        if awk -v service="${service}" '
            $0 == "  " service ":" { inside = 1; next }
            inside && $0 ~ /^  [A-Za-z0-9_-]+:$/ { inside = 0 }
            inside && $0 == "    ports:" { found = 1 }
            END { exit found ? 0 : 1 }
        ' <<< "${rendered}"; then
            echo "${service} must not publish host ports in production" >&2
            exit 1
        fi
    done

    active_services="$(${compose[@]} config --services)"
    for required_service in db backend frontend scheduler queue-worker caddy; do
        if ! grep -qx "${required_service}" <<< "${active_services}"; then
            echo "required production service is inactive: ${required_service}" >&2
            exit 1
        fi
    done
    for opt_in_service in tunnel radar-bot; do
        if grep -qx "${opt_in_service}" <<< "${active_services}"; then
            echo "opt-in service must not start by default: ${opt_in_service}" >&2
            exit 1
        fi
    done
fi

echo "production env preflight: ok"
