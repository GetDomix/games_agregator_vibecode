# KeySignal (Laravel + React)

Сравнение цен на игры: **Steam RU**, **Plati.Market**, **GGsel**.  
Аккаунты, история, избранное, целевая цена, оценка сделки, дневные лимиты поиска.

## Стек

| Слой | Технологии |
|------|------------|
| Backend | **Laravel 13** + Sanctum + Guzzle HTTP |
| Frontend | **React 19** + Vite + TypeScript + Framer Motion |
| DB | **PostgreSQL 16** (обязательно, SQLite не используется) |
| Deploy | Docker Compose + GitHub Actions |

Старый Python/FastAPI-код: `legacy/` (архив, не в проде).

## Локальный запуск

### PostgreSQL (локально)

Нужен Postgres 16. Проще всего через Docker:

```bash
# только БД
docker compose up -d db
```

Или свой инстанс: БД `gpa`, user/password как в `backend/.env.example`.

### Backend
```bash
cd backend
composer install
cp .env.example .env
# проверь DB_* → pgsql
php artisan key:generate
php artisan migrate
php artisan serve --host=127.0.0.1 --port=8080
```

### Frontend
```bash
cd frontend
npm install
npm run dev
```

Открой http://127.0.0.1:5173 — API проксируется на `:8080`.

### Docker (полный стек: Postgres + Laravel + React)
```bash
docker compose up --build
```
Сайт: http://IP/ · API: http://IP/api/health

Переменные: `POSTGRES_PASSWORD`, `APP_KEY` (сгенерируй: `php artisan key:generate --show`).

## API (кратко)

- `POST /api/auth/register|login`
- `GET /api/auth/me` (Bearer)
- `GET /api/prices?q=&appid=`
- `GET /api/me/dashboard|history|favorites`
- `POST /api/me/favorites/refresh`
- `POST /api/track/click`
- `GET /api/ads/config`
- `GET /api/health`

## Secrets (GitHub Actions)

`DEPLOY_HOST`, `DEPLOY_USER`, `DEPLOY_SSH_KEY` (+ опционально `DEPLOY_PATH`)

## Лицензия / дисклеймер

Сервис сравнивает публичные цены. Покупка — на сторонних площадках. Проверяйте продавца перед оплатой.
