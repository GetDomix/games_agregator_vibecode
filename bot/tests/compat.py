from types import SimpleNamespace
from unittest.mock import AsyncMock

from handlers.alerts import callbacks as alerts_callbacks
from handlers.alerts import reply_messages as alerts_messages
from handlers.commands import reply_messages as commands
from handlers.menu import callbacks as menu_callbacks
from handlers.search import callbacks as search_callbacks
from handlers.search import reply_messages as search_messages
from handlers.watchlist import callbacks as watchlist_callbacks
import misc
from states import watch_setup
from utils import card as card_utils


async def show_card(api, message, telegram_user_id, appid, query=None):
    previous_api = card_utils.api
    card_utils.api = api
    try:
        if query is None:
            await card_utils.show_card(message, appid)
        else:
            await card_utils.show_card(message, appid, query)
    finally:
        card_utils.api = previous_api


def make_handlers(api):
    misc.api = api
    if not isinstance(api.session, AsyncMock):
        api.session = AsyncMock(return_value={})
    modules = (alerts_callbacks, alerts_messages, commands, menu_callbacks, search_callbacks, search_messages, watchlist_callbacks)
    previous_apis = {module: getattr(module, "api", None) for module in modules}
    for module in modules:
        if hasattr(module, "api"):
            module.api = api

    async def render_card(message, appid, query=None):
        if query is None:
            await show_card(api, message, message.from_user.id, appid)
        else:
            await show_card(api, message, message.from_user.id, appid, query)

    search_callbacks.show_card = render_card
    watchlist_callbacks.show_card = render_card
    async def menu_callback(handler, message, data, *args):
        callback = SimpleNamespace(
            message=message,
            from_user=message.from_user,
            data=data,
            answer=AsyncMock(),
            chat=message.chat,
        )
        return await handler(callback, *args)

    async def menu_search(message, state):
        return await menu_callback(search_callbacks.search_menu, message, "menu_search", state)

    async def menu_favorites(message):
        return await menu_callback(alerts_callbacks.favorites_menu, message, "menu_favorites")

    async def menu_alerts(message):
        return await menu_callback(alerts_callbacks.alerts_menu, message, "menu_alerts")

    async def menu_help(message):
        return await menu_callback(menu_callbacks.help_menu, message, "menu_help")

    async def menu_home(message):
        return await menu_callback(menu_callbacks.home_menu, message, "menu_home")

    return {
        "cmd_start": commands.start,
        "cmd_help": commands.help_command,
        "cmd_search": commands.search_command,
        "cmd_favorites": commands.favorites_command,
        "cmd_alerts": commands.alerts_command,
        "menu_search": menu_search,
        "menu_favorites": menu_favorites,
        "menu_alerts": menu_alerts,
        "menu_help": menu_help,
        "menu_home": menu_home,
        "text_search": search_messages.search_game,
        "pick": search_callbacks.pick_game,
        "refresh_card": search_callbacks.refresh_card,
        "begin_watch": watchlist_callbacks.begin_watch,
        "toggle_scope": watchlist_callbacks.toggle_scope,
        "scope_done": watchlist_callbacks.finish_scope,
        "target": watchlist_callbacks.save_target,
        "favorites": alerts_callbacks.favorites_callback,
        "callback_alerts": alerts_callbacks.alerts_callback,
        "rearm": alerts_callbacks.rearm_alert,
        "remove": alerts_callbacks.remove_favorite,
    }
