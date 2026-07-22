"""
Игроскан Радар — Telegram bot (aiogram 3).

- /start CODE  — привязка аккаунта с сайта
- /help, /status
- Периодический вызов Laravel radar scan (опционально)
"""

from __future__ import annotations

import asyncio
import logging
import re

from aiogram import Bot, Dispatcher
from aiogram.filters import Command, CommandObject, CommandStart
from aiogram.types import Message
from apscheduler.schedulers.asyncio import AsyncIOScheduler

from api_client import LaravelClient
from config import get_settings

logging.basicConfig(level=logging.INFO, format="%(asctime)s %(levelname)s %(name)s: %(message)s")
log = logging.getLogger("igroscan-radar")

CODE_RE = re.compile(r"^[A-Za-z0-9]{6,16}$")


def make_handlers(api: LaravelClient):
    async def cmd_start(message: Message, command: CommandObject) -> None:
        code = (command.args or "").strip()
        if not code:
            await message.answer(
                "👋 <b>Игроскан Радар</b>\n\n"
                "Я присылаю уведомления, когда в <b>Steam</b> падает цена на игры из избранного "
                "или достигается твоя целевая цена.\n\n"
                "1. Открой сайт → Кабинет → <b>Радар</b>\n"
                "2. Нажми «Привязать Telegram»\n"
                "3. Открой ссылку или пришли сюда: <code>/start КОД</code>\n\n"
                "Команды: /help /status",
                parse_mode="HTML",
            )
            return

        code = code.strip().upper()
        if not CODE_RE.match(code):
            await message.answer("Код выглядит странно. Скопируй его с сайта целиком.")
            return

        try:
            data = await api.bind_telegram(
                code=code,
                chat_id=message.chat.id,
                username=message.from_user.username if message.from_user else None,
            )
        except Exception as e:
            log.warning("bind failed: %s", e)
            await message.answer(f"Не вышло привязать: {e}\nСгенерируй новый код в кабинете.")
            return

        name = data.get("display_name") or "игрок"
        await message.answer(
            f"✅ Готово, <b>{name}</b>!\n\n"
            "Радар включён. Добавляй игры в избранное на сайте и (по желанию) целевую цену Steam — "
            "я напишу, когда цена упадёт или цель будет достигнута.\n\n"
            "Отвязать: на сайте → Радар → «Отвязать».",
            parse_mode="HTML",
        )

    async def cmd_help(message: Message) -> None:
        await message.answer(
            "<b>Помощь</b>\n\n"
            "/start — как привязать аккаунт\n"
            "/start КОД — привязка по коду с сайта\n"
            "/status — этот chat id (для отладки)\n\n"
            "Уведомления только по <b>цене Steam</b> (избранное Игроскана).",
            parse_mode="HTML",
        )

    async def cmd_status(message: Message) -> None:
        uname = (
            f"@{message.from_user.username}"
            if message.from_user and message.from_user.username
            else "—"
        )
        await message.answer(
            f"chat_id: <code>{message.chat.id}</code>\nusername: {uname}\n\n"
            "Если уже привязан на сайте — жди уведомления после ближайшего скана.",
            parse_mode="HTML",
        )

    return cmd_start, cmd_help, cmd_status


async def radar_job(api: LaravelClient) -> None:
    try:
        data = await api.run_radar_scan()
        log.info("radar scan ok: %s", data.get("stats") or data)
    except Exception as e:
        log.error("radar scan failed: %s", e)


async def main() -> None:
    settings = get_settings()
    bot = Bot(token=settings.bot_token)
    dp = Dispatcher()
    api = LaravelClient(settings.api_base_url, settings.radar_service_token)
    cmd_start, cmd_help, cmd_status = make_handlers(api)

    dp.message.register(cmd_start, CommandStart())
    dp.message.register(cmd_help, Command("help"))
    dp.message.register(cmd_status, Command("status"))

    scheduler = AsyncIOScheduler()
    if settings.radar_trigger_hours > 0 and settings.radar_service_token:
        hours = max(1, settings.radar_trigger_hours)
        scheduler.add_job(radar_job, "interval", hours=hours, args=[api], id="radar")
        scheduler.start()
        log.info("APScheduler radar every %sh", hours)
    else:
        log.info("In-bot radar cron disabled (use php artisan schedule:work)")

    log.info("Bot @%s starting…", settings.bot_username)
    await dp.start_polling(bot)


if __name__ == "__main__":
    asyncio.run(main())
