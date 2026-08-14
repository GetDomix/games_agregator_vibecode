from aiogram.types import KeyboardButton
from aiogram.utils.keyboard import ReplyKeyboardBuilder


def main_menu_keyboard():
    builder = ReplyKeyboardBuilder()
    builder.row(KeyboardButton(text="🔎 Найти игру"))
    builder.row(KeyboardButton(text="📚 Избранное"), KeyboardButton(text="🔔 Алерты"))
    builder.row(KeyboardButton(text="❔ Помощь"), KeyboardButton(text="🏠 Главное меню"))
    return builder.as_markup(resize_keyboard=True, input_field_placeholder="Напиши название игры или выбери действие")
