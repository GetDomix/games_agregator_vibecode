from aiogram.types import KeyboardButton, ReplyKeyboardMarkup

MENU_SEARCH = "🔎 Найти игру"
MENU_FAVORITES = "📚 Избранное"
MENU_ALERTS = "🔔 Алерты"
MENU_HELP = "❔ Помощь"
MENU_HOME = "🏠 Главное меню"


def main_menu_keyboard() -> ReplyKeyboardMarkup:
    return ReplyKeyboardMarkup(keyboard=[[KeyboardButton(text=MENU_SEARCH)], [KeyboardButton(text=MENU_FAVORITES), KeyboardButton(text=MENU_ALERTS)], [KeyboardButton(text=MENU_HELP), KeyboardButton(text=MENU_HOME)]], resize_keyboard=True, input_field_placeholder="Напиши название игры или выбери действие")
