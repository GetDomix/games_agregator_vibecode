# Игроскан Радар — Telegram bot

## Имена (рекомендация)

| Поле | Значение | Примечание |
|------|----------|------------|
| **Имя** (не уникально) | `Игроскан Радар` | видно в чате |
| **Username** (уникален) | `@igroscan_radar_bot` | запасные: `@igroscan_bot`, `@igroscan_price_bot` |
| Короткое | `Igroscan Radar` | EN-вариант имени |

Проверь username в [@BotFather](https://t.me/BotFather) — если занят, возьми запасной и пропиши в env.

## Лого

| Файл | Назначение |
|------|------------|
| `bot/assets/bot_logo.jpg` | для BotFather → Set Bot Profile Photo |
| `bot/assets/bot_logo.svg` | векторный запасной (favicon-стиль) |
| `frontend/public/bot_logo.jpg` | копия на сайте |
| `frontend/public/favicon.svg` | лого сайта (скан-дуга) |

## Env

```bash
cd bot
cp .env.example .env
# TELEGRAM_BOT_TOKEN=...
# TELEGRAM_BOT_USERNAME=igroscan_radar_bot
# API_BASE_URL=https://gpa.185.100.157.180.sslip.io
# RADAR_SERVICE_TOKEN=тот_же_что_в_Laravel
```

В Laravel `.env`:

```env
TELEGRAM_BOT_TOKEN=...
TELEGRAM_BOT_USERNAME=igroscan_radar_bot
RADAR_SERVICE_TOKEN=длинный_секрет
PRICE_REFRESH_INTERVAL_HOURS=3
ANNOUNCED_STEAM_REFRESH_HOURS=24
```

## Запуск

```bash
python -m venv .venv
# Windows: .venv\Scripts\activate
pip install -r requirements.txt
PYTHONPATH=src python -m igroscan_bot
```

Расписание цен и проверку алертов запускает только Laravel scheduler. Бот — второй интерфейс того же аккаунта: ищет игры через Laravel, показывает карточку цен и управляет общим избранным/alert-ами. Он не хранит отдельную копию данных, не содержит scheduler и не запускает общий скан. `RADAR_SERVICE_TOKEN` используется только для аутентификации внутренних bot API.

## Флоу пользователя

1. Открыть бота в личном чате и нажать `/start` — Telegram-профиль сразу появится в общей базе.
2. Написать название игры или `/search`; бот покажет `typing`, затем пришлёт PNG-карточку в стиле сайта и детали под ней.
3. В карточке выбрать площадки/виды предложений и одну целевую цену. Изменения сразу видны в кабинете сайта.
4. `/favorites` и `/alerts` показывают те же данные, что и сайт; сработавший alert можно активировать снова.
5. Чтобы объединить ранее созданный сайт-аккаунт с Telegram-профилем, использовать официальный Telegram Login на сайте или старый deep-link `/start КОД`.
