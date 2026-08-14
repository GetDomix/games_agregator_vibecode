from aiogram.types import KeyboardButton, ReplyKeyboardMarkup
from aiogram.utils.keyboard import ReplyKeyboardBuilder

MENU_SEARCH = "🔎 Найти игру"
MENU_FAVORITES = "📚 Избранное"
MENU_ALERTS = "🔔 Алерты"
MENU_HELP = "❔ Помощь"
MENU_HOME = "🏠 Главное меню"


def main_menu_keyboard() -> ReplyKeyboardMarkup:
    builder = ReplyKeyboardBuilder()
    builder.row(KeyboardButton(text=MENU_SEARCH))
    builder.row(KeyboardButton(text=MENU_FAVORITES), KeyboardButton(text=MENU_ALERTS))
    builder.row(KeyboardButton(text=MENU_HELP), KeyboardButton(text=MENU_HOME))
    return builder.as_markup(resize_keyboard=True, input_field_placeholder="Напиши название игры или выбери действие")
