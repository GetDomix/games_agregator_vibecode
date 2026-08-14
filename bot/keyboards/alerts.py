from aiogram.types import InlineKeyboardButton
from aiogram.utils.keyboard import InlineKeyboardBuilder


def alerts_keyboard(items: list[dict], status: str):
    builder = InlineKeyboardBuilder()
    if status == "triggered":
        for item in items[:20]:
            builder.row(InlineKeyboardButton(text=f"↻ {item['favorite']['game_name']}", callback_data=f"rearm:{item['favorite']['appid']}"))
    builder.row(InlineKeyboardButton(text="🟢 Активные", callback_data="alerts:active"), InlineKeyboardButton(text="🔔 Сработавшие", callback_data="alerts:triggered"))
    return builder.as_markup()
