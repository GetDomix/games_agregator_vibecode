from typing import Iterable

from aiogram.types import InlineKeyboardButton
from aiogram.utils.keyboard import InlineKeyboardBuilder


def candidates_keyboard(items: Iterable[dict]):
    builder = InlineKeyboardBuilder()
    for item in items:
        builder.row(InlineKeyboardButton(text=f"🎮 {item['name']}", callback_data=f"pick:{item['appid']}"))
    return builder.as_markup()


def card_keyboard(appid: int, is_favorite: bool):
    builder = InlineKeyboardBuilder()
    watch = "⚙️ Настроить отслеживание" if is_favorite else "⭐ Добавить и настроить"
    builder.row(InlineKeyboardButton(text=watch, callback_data=f"watch:{appid}"))
    builder.row(InlineKeyboardButton(text="🔄 Обновить карточку", callback_data=f"card:{appid}"), InlineKeyboardButton(text="📚 Избранное", callback_data="favorites"))
    if is_favorite:
        builder.row(InlineKeyboardButton(text="🗑 Убрать из избранного", callback_data=f"remove:{appid}"))
    return builder.as_markup()


def scopes_keyboard(appid: int, selected: set[tuple[str, str]]):
    builder = InlineKeyboardBuilder()
    for source, kinds in (("steam", ["official"]), ("plati", ["key", "gift", "account", "rent"]), ("ggsel", ["key", "gift", "account", "rent"])):
        row = [InlineKeyboardButton(text=f"{'✅' if (source, kind) in selected else '▫️'} {'Steam' if source == 'steam' else source.title() + ': ' + kind}", callback_data=f"scope:{appid}:{source}:{kind}") for kind in kinds]
        for index in range(0, len(row), 2):
            builder.row(*row[index:index + 2])
    builder.row(InlineKeyboardButton(text="Далее: целевая цена →", callback_data=f"scope_done:{appid}"))
    return builder.as_markup()
