import asyncio
import logging

from misc import configure, dispatcher as dp
from utils.setup_all_routers import setup_all_routers


async def main() -> None:
    bot = configure()
    setup_all_routers(dp)
    logging.getLogger("igroscan-bot").info("Starting Telegram bot")
    await dp.start_polling(bot)




if __name__ == "__main__":
    logging.basicConfig(level=logging.INFO)
    asyncio.run(main())
