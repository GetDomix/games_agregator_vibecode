# Game Price Aggregator

**Production-ready MVP:** сравни цены на игры (Steam RU · Plati.Market · GGsel), аккаунты, история, избранное, цели по цене, рекламные билборды, Docker.

## Возможности

| Фича | Описание |
|------|----------|
| Поиск цен | Steam + Plati + GGsel, мин/средняя по типу (ключ/гифт/акк/аренда) |
| Гость | Ищет без регистрации |
| Регистрация / JWT | Email + пароль, 7 дней токен |
| История | Автосохранение поиска при логине |
| Избранное | ☆ + целевая цена Steam, бейдж «на цели» |
| Кабинет | Статы, CTA, продолжить, очистка истории |
| Тренды | Популярные запросы сообщества (seed при пустой БД) |
| Реклама | Placeholder-билборды + `ADS_*` env |
| Партнёрка | `DIGISELLER_PARTNER_ID` → `ai=` на ссылках |

## Быстрый старт (локально)

```powershell
python -m venv .venv
.\.venv\Scripts\activate
pip install -r requirements-dev.txt
copy .env.example .env
# сгенерируй SECRET_KEY!
uvicorn app.main:app --reload --host 0.0.0.0 --port 8000
```

Открой http://127.0.0.1:8000

## Docker (локально / VPS по IP)

```powershell
# .env:
# APP_ENV=production
# SECRET_KEY=<длинная случайная строка>
# CORS_ORIGINS=*

docker compose up --build -d
```

Сайт: `http://127.0.0.1:8000` или `http://IP_СЕРВЕРА:8000`  
Данные SQLite в volume `gpa_data` (`/data/app.db`).

## CI/CD (GitHub Actions) — без домена, по IP

Уже в репо:

| Workflow | Файл | Когда |
|----------|------|--------|
| **CI** | `.github/workflows/ci.yml` | push/PR → pytest + docker smoke |
| **Deploy** | `.github/workflows/deploy.yml` | push в `main`/`master` → rsync + `docker compose up` + healthcheck |

### Один раз на VPS

```bash
curl -fsSL https://get.docker.com | sh
adduser deploy && usermod -aG docker deploy   # или root
mkdir -p /opt/gpa && chown deploy:deploy /opt/gpa
# положить SSH public key в authorized_keys
# открыть порт 8000/tcp
```

Подробнее: [`deploy/README.md`](deploy/README.md).

### Secrets в GitHub (Settings → Secrets → Actions)

| Secret | Значение |
|--------|----------|
| `DEPLOY_HOST` | IP VPS, например `203.0.113.10` |
| `DEPLOY_USER` | `deploy` |
| `DEPLOY_SSH_KEY` | приватный ключ целиком |
| `DEPLOY_PATH` | `/opt/gpa` (опционально) |
| `DEPLOY_HTTP_PORT` | `8000` (опционально) |
| `APP_SECRET_KEY` | длинный секрет (опционально, иначе сгенерится на сервере) |

После `git push origin main` → Actions → Deploy → сайт: **`http://IP:8000/`**.

### Postgres (опционально)

```env
DATABASE_URL=postgresql+psycopg://user:pass@host:5432/gpa
```

Добавь `psycopg[binary]` в зависимости при переходе на Postgres.

## Env

| Переменная | Назначение |
|------------|------------|
| `SECRET_KEY` | JWT signing — **смени в проде** |
| `DATABASE_URL` | SQLite или Postgres |
| `APP_ENV` | `development` / `production` |
| `CORS_ORIGINS` | `*` или `https://your.domain` |
| `DIGISELLER_PARTNER_ID` | партнёрские ссылки |
| `ADS_ENABLED` | билборды on/off |
| `ADS_CONTACT_EMAIL` | mailto CTA |

## API (кратко)

- `POST /api/auth/register` `{email,password,display_name?}`
- `POST /api/auth/login`
- `GET /api/auth/me` (Bearer)
- `GET /api/prices?q=&appid=` (+ Bearer → history)
- `GET /api/me/history` · `DELETE /api/me/history`
- `GET|POST /api/me/favorites` · `PATCH|DELETE /api/me/favorites/{appid}`
- `GET /api/me/dashboard`
- `GET /api/trends/popular`
- `GET /api/ads/config`
- `GET /api/health`

## Тесты

```powershell
pytest -v
```

## Монетизация сегодня

1. **Партнёрка Digiseller** — `DIGISELLER_PARTNER_ID`
2. **Прямые баннеры** — слоты уже на странице, контакт в `ADS_CONTACT_EMAIL`
3. **Позже** — подставить `html`/`image_url` в `/api/ads/config` (РСЯ/AdSense)

## Удержание

- История + «Продолжить»
- Избранное + цель по цене
- Популярное сейчас
- Soft-banner после 2 гостевых поисков
- CTA в кабинете

## Безопасность (MVP)

- bcrypt пароли, JWT HS256
- Rate limit in-memory на login/prices
- Security headers middleware
- **HTTPS обязателен** в проде (токен в `localStorage`)
- Не коммить реальный `SECRET_KEY`

## Структура

```
app/
  main.py
  db.py / db_models.py / schemas.py
  auth/
  routers/   # auth, prices, history, favorites, dashboard, ads
  services/  # steam, plati, ggsel, ads, persistence
  static/    # UI
```

## Лицензия / дисклеймер

Неофициальный сервис. Цены ориентировочные. Соблюдай ToS площадок и законы о рекламе/ПДн в своей юрисдикции.
