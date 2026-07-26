#!/usr/bin/env bash
# One-time bootstrap on a fresh Ubuntu/Debian VPS (run as root or with sudo).
# Usage:
#   curl -fsSL ... | bash   # or
#   bash deploy/setup-server.sh
set -euo pipefail

DEPLOY_PATH="${DEPLOY_PATH:-/opt/gpa}"

echo "==> Install Docker if needed"
if ! command -v docker >/dev/null 2>&1; then
  apt-get update -y
  apt-get install -y ca-certificates curl gnupg rsync openssh-server
  install -m 0755 -d /etc/apt/keyrings
  curl -fsSL https://download.docker.com/linux/ubuntu/gpg | gpg --dearmor -o /etc/apt/keyrings/docker.gpg
  chmod a+r /etc/apt/keyrings/docker.gpg
  # Fallback: get.docker.com (works on most Debian/Ubuntu)
  curl -fsSL https://get.docker.com | sh
  systemctl enable --now docker
fi

echo "==> Docker compose plugin"
docker compose version

echo "==> App directory ${DEPLOY_PATH}"
mkdir -p "$DEPLOY_PATH"
chmod 755 "$DEPLOY_PATH"

echo "==> Firewall (ufw) — open SSH + HTTP/HTTPS"
if command -v ufw >/dev/null 2>&1; then
  ufw allow OpenSSH || true
  ufw allow 80/tcp || true
  ufw allow 443/tcp || true
  ufw allow 443/udp || true
  # Don't force-enable ufw if user never used it
  echo "ufw rules added (enable manually: ufw enable)"
fi

echo "==> Done. Next:"
echo "  1) Add GitHub Secrets (DEPLOY_HOST=IP, DEPLOY_USER, DEPLOY_SSH_KEY, DEPLOY_PATH=${DEPLOY_PATH})"
echo "  2) Configure required reviewers for the GitHub environment: production"
echo "  3) Run Actions → Pipeline manually on main/master with deploy_production=true"
echo "  4) Open https://gpa.YOUR_IP.sslip.io/"
