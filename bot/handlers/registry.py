from aiogram import Dispatcher, F
from aiogram.filters import Command, CommandStart

from states import WatchSetup
from ui import MENU_ALERTS, MENU_FAVORITES, MENU_HELP, MENU_HOME, MENU_SEARCH


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
