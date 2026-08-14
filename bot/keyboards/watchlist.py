from aiogram.types import InlineKeyboardButton
from aiogram.utils.keyboard import InlineKeyboardBuilder


def favorites_keyboard(items: list[dict]):
    builder = InlineKeyboardBuilder()
    for item in items[:20]:
        builder.row(InlineKeyboardButton(text=f"🎮 {item['game_name']}", callback_data=f"card:{item['appid']}"))
    return builder.as_markup()
