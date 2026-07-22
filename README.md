# KeySignal (Laravel + React)

Сравнение цен на игры: **Steam RU**, **Plati.Market**, **GGsel**.  
Аккаунты, история, избранное, целевая цена, оценка сделки, дневные лимиты поиска.

## Стек

| Слой | Технологии |
|------|------------|
| Backend | **Laravel 13** + Sanctum + Guzzle HTTP |
| Frontend | **React 19** + Vite + TypeScript + Framer Motion |
| DB | SQLite (по умолчанию) / Postgres ready |
| Deploy | Docker Compose + GitHub Actions |

Старый Python/FastAPI-код: `legacy/` (архив, не в проде).

## Локальный запуск

### Backend
```bash
cd backend
composer install
cp .env.example .env   # если нужно
php artisan key:generate
touch database/database.sqlite
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

### Docker
```bash
docker compose up --build
```
Сайт: http://IP/ · API: http://IP/api/health

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
