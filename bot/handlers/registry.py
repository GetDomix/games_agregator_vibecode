from aiogram import Dispatcher, F
from aiogram.filters import Command, CommandStart

from states import WatchSetup
from keyboards import MENU_ALERTS, MENU_FAVORITES, MENU_HELP, MENU_HOME, MENU_SEARCH
from .alerts import handlers as alert_handlers
from .cards import show_card
from .commands import handlers as command_handlers
from .menu import handlers as menu_handlers
from .menu import show_main_menu
from .search import handlers as search_handlers
from .watchlist import handlers as watchlist_handlers


def make_handlers(api, show_card_fn=None):
    show_card_fn = show_card_fn or show_card
    commands = command_handlers(api, show_main_menu)
    search = search_handlers(api, show_card_fn)
    watchlist = watchlist_handlers(api, show_card_fn)
    return {**commands, **search, **watchlist, **alert_handlers(api), **menu_handlers(api, commands["cmd_search"])}


def register_handlers(dispatcher: Dispatcher, handlers: dict) -> None:
    dispatcher.message.register(handlers["cmd_start"], CommandStart())
    dispatcher.message.register(handlers["cmd_help"], Command("help"))
    dispatcher.message.register(handlers["cmd_search"], Command("search"))
    dispatcher.message.register(handlers["cmd_favorites"], Command("favorites"))
    dispatcher.message.register(handlers["cmd_alerts"], Command("alerts"))
    dispatcher.message.register(handlers["menu_search"], F.text == MENU_SEARCH)
    dispatcher.message.register(handlers["menu_favorites"], F.text == MENU_FAVORITES)
    dispatcher.message.register(handlers["menu_alerts"], F.text == MENU_ALERTS)
    dispatcher.message.register(handlers["menu_help"], F.text == MENU_HELP)
    dispatcher.message.register(handlers["menu_home"], F.text == MENU_HOME)
    dispatcher.callback_query.register(handlers["pick"], F.data.startswith("pick:"))
    dispatcher.callback_query.register(handlers["refresh_card"], F.data.startswith("card:"))
    dispatcher.callback_query.register(handlers["begin_watch"], F.data.startswith("watch:"))
    dispatcher.callback_query.register(handlers["toggle_scope"], F.data.startswith("scope:"))
    dispatcher.callback_query.register(handlers["scope_done"], F.data.startswith("scope_done:"))
    dispatcher.callback_query.register(handlers["favorites"], F.data == "favorites")
    dispatcher.callback_query.register(handlers["callback_alerts"], F.data.startswith("alerts:"))
    dispatcher.callback_query.register(handlers["rearm"], F.data.startswith("rearm:"))
    dispatcher.callback_query.register(handlers["remove"], F.data.startswith("remove:"))
    dispatcher.message.register(handlers["target"], WatchSetup.entering_target)
    dispatcher.message.register(handlers["text_search"], F.text)
