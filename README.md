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

Production Compose запускает отдельные процессы `backend`, `scheduler` и
`queue-worker`. Только `backend` выполняет миграции; scheduler и worker стартуют
после его healthcheck. Единственный владелец расписания обновления цен — Laravel.

## Админка

- Поле `users.is_admin` или env `ADMIN_EMAILS=you@mail.com`
- UI: кабинет → «Админка» / кнопка Admin (desktop)
- Операционный экран показывает здоровье источников, очередь, доставки алертов,
  проблемные запросы, пользователей и журнал действий.
- API: `GET /api/admin/overview`, `GET /api/admin/users`,
  `POST /api/admin/users/{id}/admin`, `POST /api/admin/games/{appid}/refresh`
- Ручное обновление принимает `sources: ["steam", "plati", "ggsel"]` и только
  ставит штатные фоновые задачи в очередь; цены из админки не редактируются.

Поиск бесплатен для гостей и зарегистрированных пользователей. Защита API от спама обеспечивается техническими rate limits.

## Радар (Telegram)

См. [`bot/README.md`](bot/README.md).

- Имя бота: **Игроскан Радар**
- Username (проверить в BotFather): **`@igroscan_radar_bot`**
- Лого: `bot/assets/bot_logo.jpg`
- Расписание: `php artisan schedule:work` в отдельном Compose-сервисе
- Бот: `cd bot && pip install -r requirements.txt && python main.py`

## Проверки и выпуск

CI проверяет Laravel на PostgreSQL, frontend lint/build, изолированные тесты бота
и сборку Compose-образов. Production deploy запускается только вручную через
GitHub Actions и защищённое environment `production`.

Инструкции: [`deploy/README.md`](deploy/README.md) и
[`deploy/RELEASE_RUNBOOK.md`](deploy/RELEASE_RUNBOOK.md).
