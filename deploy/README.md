# Deploy by IP (no domain)

## 1. One-time: prepare VPS

SSH as root/sudo:

```bash
# install docker
curl -fsSL https://get.docker.com | sh
# create deploy user (optional but better)
adduser deploy
usermod -aG docker deploy
mkdir -p /opt/gpa && chown deploy:deploy /opt/gpa
```

Or run `deploy/setup-server.sh` as root.

Put your **public** SSH key in `~/.ssh/authorized_keys` for the deploy user.

Open firewall port **8000/tcp** (and 22).

## 2. GitHub Secrets

Repo → **Settings → Secrets and variables → Actions**:

| Secret | Example | Required |
|--------|---------|----------|
| `DEPLOY_HOST` | `203.0.113.10` | yes (VPS **IP**) |
| `DEPLOY_USER` | `deploy` | yes |
| `DEPLOY_SSH_KEY` | private key PEM (`-----BEGIN ...`) | yes |
| `DEPLOY_PATH` | `/opt/gpa` | no (default `/opt/gpa`) |
| `DEPLOY_PORT` | `22` | no |
| `DEPLOY_HTTP_PORT` | `8000` | no |
| `APP_SECRET_KEY` | long random string | no (generated once on server if empty) |
| `DIGISELLER_PARTNER_ID` | partner id | no |
| `ADS_CONTACT_EMAIL` | you@mail | no |

Also create Environment **production** (Settings → Environments) if you want protection rules; the workflow references `environment: production`.

## 3. Push → auto pipeline

```bash
git push origin master
```

Один workflow **Pipeline** (`.github/workflows/pipeline.yml`):

1. **Tests** — pytest  
2. **Docker build** — image + healthcheck  
3. **Deploy VPS** — только `master`/`main`: rsync → compose up → `http://IP:8000/api/health`

PR/develop: шаги 1–2 без деплоя.

Manual: Actions → **Pipeline** → Run workflow.

## 4. Open site

```text
http://YOUR_IP:8000/
http://YOUR_IP:8000/api/health
```

No domain needed. Later point A-record to the same IP.

## 5. Server .env

First deploy creates `/opt/gpa/.env` if missing. Later deploys **do not overwrite** it (your SECRET_KEY and DB stay).

To rotate secret: SSH and edit `.env`, then:

```bash
cd /opt/gpa && docker compose up -d --build
```

## SSH key pair (if you don't have one)

```bash
ssh-keygen -t ed25519 -C "gpa-deploy" -f gpa_deploy -N ""
# public → server authorized_keys
# private → GitHub secret DEPLOY_SSH_KEY (full file contents)
```
