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
RADAR_INTERVAL_HOURS=6
```

## Запуск

```bash
python -m venv .venv
# Windows: .venv\Scripts\activate
pip install -r requirements.txt
python main.py
```

Скан цен: `php artisan radar:scan` или `php artisan schedule:work` (cron каждый N часов).  
Бот также может дергать `POST /api/internal/radar/run` раз в `RADAR_TRIGGER_HOURS`.

## Флоу пользователя

1. Сайт → Кабинет → Радар → «Привязать Telegram»
2. Открыть deep-link или `/start КОД` боту
3. Добавить игры в избранное + целевую цену (опционально)
4. Уведомления: цель Steam / падение Steam ≥5% или ≥30 ₽
