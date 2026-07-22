# Игроскан (Igroscan)

Агрегатор цен на игры: **Steam RU · Plati.Market · GGsel**.

Стек: Laravel API + React (Vite) + PostgreSQL + Docker (Caddy HTTPS).

## Локально

```bash
# backend
cd backend && cp .env.example .env && composer install && php artisan key:generate
# frontend
cd frontend && npm install && npm run dev
```

## Админка

- Поле `users.is_admin` или env `ADMIN_EMAILS=you@mail.com`
- UI: кабинет → «Админка» / кнопка Admin (desktop)
- API: `GET /api/admin/overview`, `POST /api/admin/users/{id}/plan`

## Pro

- 99 ₽/мес · 790 ₽/год (env)
- Промокод: `KEYSIGNAL-PRO` (30 дней)

## Радар (Telegram)

См. [`bot/README.md`](bot/README.md).

- Имя бота: **Игроскан Радар**
- Username (проверить в BotFather): **`@igroscan_radar_bot`**
- Лого: `bot/assets/bot_logo.jpg`
- Скан: `php artisan radar:scan` + `php artisan schedule:work`
- Бот: `cd bot && pip install -r requirements.txt && python main.py`
