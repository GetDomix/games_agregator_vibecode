from aiogram.types import InlineKeyboardButton
from aiogram.utils.keyboard import InlineKeyboardBuilder


def main_menu_keyboard():
    builder = InlineKeyboardBuilder()
    builder.row(InlineKeyboardButton(text="🔎 Найти игру", callback_data="menu_search"))
    builder.row(
        InlineKeyboardButton(text="📚 Избранное", callback_data="menu_favorites"),
        InlineKeyboardButton(text="🔔 Алерты", callback_data="menu_alerts"),
    )
    builder.row(
        InlineKeyboardButton(text="❔ Помощь", callback_data="menu_help"),
        InlineKeyboardButton(text="🏠 Главное меню", callback_data="menu_home"),
    )
    return builder.as_markup()
