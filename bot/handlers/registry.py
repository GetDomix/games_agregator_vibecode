from aiogram import Dispatcher

from . import alerts, commands, menu, search, watchlist


def setup_all_routers(dispatcher: Dispatcher) -> None:
    dispatcher.include_routers(
        commands.router,
        menu.router,
        search.router,
        watchlist.router,
        alerts.router,
    )
