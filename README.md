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

- Укажите свой email в `ADMIN_EMAILS=you@example.com`, перезапустите backend
  (при кэшированной конфигурации выполните `php artisan config:clear`) и войдите
  на сайт под аккаунтом с тем же email. Этот allowlist создаёт неотзываемую
  эффективную роль `owner` для аварийного серверного доступа.
- Откройте кабинет → «Админка». Рабочее пространство содержит «Обзор»,
  «Каталог», «Пользователи», «Команда» и «Аудит»; вкладка «Команда» видна
  только владельцам.
- Владелец назначает другим зарегистрированным пользователям роли `admin` или
  `owner` во вкладке «Команда». Переход в `owner` или из него требует текущий
  пароль. Последнего и server-managed владельца понизить нельзя.
- Любая смена роли отзывает активные API-сессии целевого пользователя. Обычный
  `admin` управляет операционными задачами, но не ролями и не видит role-аудит.
- API: `GET /api/admin/overview`, `GET /api/admin/users`,
  `GET /api/admin/audit`, `GET /api/admin/team`,
  `PATCH /api/admin/team/{user}`, `POST /api/admin/games/{appid}/refresh`.
- Ручное обновление принимает `sources: ["steam", "plati", "ggsel"]` и только
  ставит штатные фоновые задачи в очередь; цены из админки не редактируются.

Модель угроз, результаты и повторяемые команды находятся в
[`docs/SECURITY_REVIEW.md`](archive/docs/SECURITY_REVIEW.md).

Поиск бесплатен для гостей и зарегистрированных пользователей. Защита API от спама обеспечивается техническими rate limits.

## Радар (Telegram)

См. [`bot/README.md`](bot/README.md).

- Имя бота: **Игроскан Радар**
- Username (проверить в BotFather): **`@igroscan_radar_bot`**
- Лого: `bot/assets/bot_logo.jpg`
- Расписание: `php artisan schedule:work` в отдельном Compose-сервисе
- Бот: `cd bot && pip install -r requirements.txt && PYTHONPATH=src python -m igroscan_bot`

## Проверки и выпуск

CI проверяет Laravel на PostgreSQL, locked Composer dependencies, frontend
lint/build, изолированные тесты бота и оба Compose-конфига. Production deploy
по умолчанию отключён и не запускается при push: в будущем понадобятся ручной
workflow, отдельная repository variable и approval environment `production`.

Инструкции: [`deploy/README.md`](deploy/README.md) и
[`deploy/RELEASE_RUNBOOK.md`](deploy/RELEASE_RUNBOOK.md). Политика резервного
копирования: [`deploy/BACKUP_POLICY.md`](deploy/BACKUP_POLICY.md).
