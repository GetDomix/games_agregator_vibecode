from aiogram.types import InlineKeyboardButton, InlineKeyboardMarkup


def favorites_keyboard(items: list[dict]) -> InlineKeyboardMarkup:
    return InlineKeyboardMarkup(inline_keyboard=[[InlineKeyboardButton(text=f"🎮 {item['game_name']}", callback_data=f"card:{item['appid']}")] for item in items[:20]])


def alerts_keyboard(items: list[dict], status: str) -> InlineKeyboardMarkup:
    rows = []
    if status == "triggered":
        rows.extend([[InlineKeyboardButton(text=f"↻ {item['favorite']['game_name']}", callback_data=f"rearm:{item['favorite']['appid']}")] for item in items[:20]])
    rows.append([InlineKeyboardButton(text="🟢 Активные", callback_data="alerts:active"), InlineKeyboardButton(text="🔔 Сработавшие", callback_data="alerts:triggered")])
    return InlineKeyboardMarkup(inline_keyboard=rows)
