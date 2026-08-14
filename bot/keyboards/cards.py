from typing import Iterable

from aiogram.types import InlineKeyboardButton, InlineKeyboardMarkup

from texts import SCOPE_LABELS


def candidates_keyboard(items: Iterable[dict]) -> InlineKeyboardMarkup:
    return InlineKeyboardMarkup(inline_keyboard=[[InlineKeyboardButton(text=f"🎮 {item['name']}", callback_data=f"pick:{item['appid']}")] for item in items])


def card_keyboard(appid: int, is_favorite: bool) -> InlineKeyboardMarkup:
    watch = "⚙️ Настроить отслеживание" if is_favorite else "⭐ Добавить и настроить"
    rows = [[InlineKeyboardButton(text=watch, callback_data=f"watch:{appid}")], [InlineKeyboardButton(text="🔄 Обновить карточку", callback_data=f"card:{appid}"), InlineKeyboardButton(text="📚 Избранное", callback_data="favorites")]]
    if is_favorite:
        rows.append([InlineKeyboardButton(text="🗑 Убрать из избранного", callback_data=f"remove:{appid}")])
    return InlineKeyboardMarkup(inline_keyboard=rows)


def scopes_keyboard(appid: int, selected: set[tuple[str, str]]) -> InlineKeyboardMarkup:
    rows = []
    for source, kinds in (("steam", ["official"]), ("plati", ["key", "gift", "account", "rent"]), ("ggsel", ["key", "gift", "account", "rent"])):
        row = [InlineKeyboardButton(text=f"{'✅' if (source, kind) in selected else '▫️'} {SCOPE_LABELS[(source, kind)]}", callback_data=f"scope:{appid}:{source}:{kind}") for kind in kinds]
        rows.extend([row[index:index + 2] for index in range(0, len(row), 2)])
    rows.append([InlineKeyboardButton(text="Далее: целевая цена →", callback_data=f"scope_done:{appid}")])
    return InlineKeyboardMarkup(inline_keyboard=rows)
