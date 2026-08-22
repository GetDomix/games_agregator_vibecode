# Технологический стек

## Frontend — `frontend/`

- React 19, TypeScript, Vite 8.
- React Router и Framer Motion.
- Проверки: `npm run lint`, сборка: `npm run build`.

## Backend — `backend/`

- PHP 8.3+, Laravel 13, Laravel Sanctum.
- PostgreSQL 16.
- Тесты: `php artisan test`.

## Bot — `bot/`

- Python.
- aiogram 3, httpx, APScheduler, python-dotenv.

## Инфраструктура

- Docker Compose: PostgreSQL, backend, frontend, Caddy, Cloudflare Tunnel и radar-bot.
- GitHub Actions: backend-тесты с PostgreSQL, сборка фронтенда, затем деплой с веток `main`/`master`.
