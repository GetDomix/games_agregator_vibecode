from __future__ import annotations

import asyncio
import logging

from aiogram import Bot, Dispatcher

from api import LaravelClient
from config import get_settings
from handlers.cards import show_card
from handlers.menu import show_main_menu
from handlers.registry import make_handlers as build_handlers, register_handlers
from states import WatchSetup
from utils.telegram import session


logging.basicConfig(level=logging.INFO, format="%(asctime)s %(levelname)s %(name)s: %(message)s")
log = logging.getLogger("igroscan-bot")


def make_handlers(api: LaravelClient):
    return build_handlers(api, show_card)


async def main() -> None:
    settings = get_settings()
    bot = Bot(token=settings.bot_token)
    dispatcher = Dispatcher()
    api = LaravelClient(settings.api_base_url, settings.radar_service_token)
    register_handlers(dispatcher, make_handlers(api))
    log.info("Bot @%s starting", settings.bot_username)
    await dispatcher.start_polling(bot)


if __name__ == "__main__":
    asyncio.run(main())
