import asyncio
import logging

from misc import bot, dispatcher
from handlers.registry import setup_all_routers
from states import WatchSetup
from utils.telegram import session


async def main() -> None:
    setup_all_routers(dispatcher)
    logging.getLogger("igroscan-bot").info("Starting Telegram bot")
    await dispatcher.start_polling(bot)


async def show_card(api_client, message, telegram_user_id, appid, query=None):
    import handlers.cards as cards
    previous_api = cards.api
    cards.api = api_client
    try:
        await cards.show_card(message, appid, query)
    finally:
        cards.api = previous_api


def make_handlers(api_client):
    import handlers.alerts as alerts
    import handlers.commands as commands
    import handlers.menu as menu
    import handlers.search as search
    import handlers.watchlist as watchlist

    modules = (alerts, commands, menu, search, watchlist)
    previous_apis = {module: module.api for module in modules}
    for module in modules:
        module.api = api_client

    async def render_card(message, appid, query=None):
        if query is None:
            await show_card(api_client, message, message.from_user.id, appid)
        else:
            await show_card(api_client, message, message.from_user.id, appid, query)

    search.show_card = render_card
    watchlist.show_card = render_card
    return {
        "cmd_start": commands.start,
        "cmd_help": commands.help_command,
        "cmd_search": commands.search_command,
        "cmd_favorites": commands.favorites_command,
        "cmd_alerts": commands.alerts_command,
        "menu_search": search.search_menu,
        "menu_favorites": alerts.favorites_menu,
        "menu_alerts": alerts.alerts_menu,
        "menu_help": menu.help_menu,
        "menu_home": menu.home_menu,
        "text_search": search.search_game,
        "pick": search.pick_game,
        "refresh_card": search.refresh_card,
        "begin_watch": watchlist.begin_watch,
        "toggle_scope": watchlist.toggle_scope,
        "scope_done": watchlist.finish_scope,
        "target": watchlist.save_target,
        "favorites": alerts.favorites_callback,
        "callback_alerts": alerts.alerts_callback,
        "rearm": alerts.rearm_alert,
        "remove": alerts.remove_favorite,
    }


if __name__ == "__main__":
    logging.basicConfig(level=logging.INFO)
    asyncio.run(main())
