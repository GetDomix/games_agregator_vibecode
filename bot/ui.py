from __future__ import annotations

from html import escape
from typing import Iterable

from aiogram.types import (InlineKeyboardButton, InlineKeyboardMarkup,
                           KeyboardButton, ReplyKeyboardMarkup)


SCOPE_LABELS = {
    ("steam", "official"): "Steam",
    ("plati", "key"): "Plati: ключ",
    ("plati", "gift"): "Plati: gift",
    ("plati", "account"): "Plati: аккаунт",
    ("plati", "rent"): "Plati: аренда",
    ("ggsel", "key"): "GGsel: ключ",
    ("ggsel", "gift"): "GGsel: gift",
    ("ggsel", "account"): "GGsel: аккаунт",
    ("ggsel", "rent"): "GGsel: аренда",
}

MENU_SEARCH = "🔎 Найти игру"
MENU_FAVORITES = "📚 Избранное"
MENU_ALERTS = "🔔 Алерты"
MENU_HELP = "❔ Помощь"
MENU_HOME = "🏠 Главное меню"


def main_menu_keyboard() -> ReplyKeyboardMarkup:
    return ReplyKeyboardMarkup(
        keyboard=[
            [KeyboardButton(text=MENU_SEARCH)],
            [KeyboardButton(text=MENU_FAVORITES), KeyboardButton(text=MENU_ALERTS)],
            [KeyboardButton(text=MENU_HELP), KeyboardButton(text=MENU_HOME)],
        ],
        resize_keyboard=True,
        input_field_placeholder="Напиши название игры или выбери действие",
    )


def price(value: object | None) -> str:
    if value is None:
        return "—"
    try:
        return f"{float(value):,.0f} ₽".replace(",", " ")
    except (TypeError, ValueError):
        return "—"


def official_price(steam: dict) -> str:
    if steam.get("price_rub") is not None:
        return price(steam["price_rub"])
    regions = steam.get("regional_prices") or []
    preferred = next((item for item in regions if item.get("region") == "US"), regions[0] if regions else None)
    if not preferred:
        return "—"
    amount = preferred.get("amount")
    currency = str(preferred.get("currency") or "")
    try:
        numeric = float(amount)
    except (TypeError, ValueError):
        return "—"
    symbols = {"USD": "$", "EUR": "€", "TRY": "₺", "KZT": "₸"}
    rendered = f"{symbols.get(currency, currency + ' ')}{numeric:,.2f}".replace(",", " ")
    converted = preferred.get("price_rub")
    return f"{rendered} (≈ {price(converted)})" if converted is not None else rendered


def candidates_keyboard(items: Iterable[dict]) -> InlineKeyboardMarkup:
    rows = [[InlineKeyboardButton(text=f"🎮 {item['name']}", callback_data=f"pick:{item['appid']}")]
            for item in items]
    return InlineKeyboardMarkup(inline_keyboard=rows)


def card_keyboard(appid: int, is_favorite: bool) -> InlineKeyboardMarkup:
    watch = "⚙️ Настроить отслеживание" if is_favorite else "⭐ Добавить и настроить"
    rows = [
        [InlineKeyboardButton(text=watch, callback_data=f"watch:{appid}")],
        [InlineKeyboardButton(text="🔄 Обновить карточку", callback_data=f"card:{appid}"),
         InlineKeyboardButton(text="📚 Избранное", callback_data="favorites")],
    ]
    if is_favorite:
        rows.append([InlineKeyboardButton(text="🗑 Убрать из избранного", callback_data=f"remove:{appid}")])
    return InlineKeyboardMarkup(inline_keyboard=rows)


def scopes_keyboard(appid: int, selected: set[tuple[str, str]]) -> InlineKeyboardMarkup:
    rows: list[list[InlineKeyboardButton]] = []
    for source, kinds in (("steam", ["official"]), ("plati", ["key", "gift", "account", "rent"]), ("ggsel", ["key", "gift", "account", "rent"])):
        row = []
        for kind in kinds:
            mark = "✅" if (source, kind) in selected else "▫️"
            row.append(InlineKeyboardButton(text=f"{mark} {SCOPE_LABELS[(source, kind)]}", callback_data=f"scope:{appid}:{source}:{kind}"))
        rows.extend([row[i:i + 2] for i in range(0, len(row), 2)])
    rows.append([InlineKeyboardButton(text="Далее: целевая цена →", callback_data=f"scope_done:{appid}")])
    return InlineKeyboardMarkup(inline_keyboard=rows)


def format_card_details(card: dict, favorite: dict | None) -> str:
    steam = card.get("steam") or {}
    lines = [f"<b>{escape(str(steam.get('name') or 'Игра'))}</b>"]
    if card.get("refreshing"):
        lines.append("⏳ Цены обновляются в фоне; на карточке — последнее сохранённое состояние.")
    if steam.get("note"):
        lines.append(f"🗓 {escape(str(steam['note']))}")
    regional = steam.get("regional_prices") or []
    if regional:
        values = []
        for item in regional:
            values.append(f"{escape(str(item.get('label') or item.get('region')))}: {escape(official_price({'regional_prices': [item]}))}")
        lines.append("🌍 Steam по регионам: " + " · ".join(values))
    if favorite and favorite.get("alert"):
        alert = favorite["alert"]
        target = alert.get("target_value")
        state = "сработал" if alert.get("status") == "triggered" else "активен"
        lines.append(f"🔔 Alert {state}: {price(target)}")
    lines.append("Цены и типы предложений — на карточке.")
    return "\n".join(lines)


def format_favorites(items: list[dict]) -> str:
    if not items:
        return "В избранном пока пусто. Найди игру — и добавь её с карточки."
    lines = ["<b>Избранное</b>"]
    for item in items[:20]:
        alert = item.get("alert") or {}
        suffix = f" · цель {price(alert.get('target_value'))}" if alert.get("target_value") is not None else ""
        lines.append(f"• <b>{escape(str(item.get('game_name')))}</b> — Steam {price(item.get('last_steam_price_rub'))}{suffix}")
    return "\n".join(lines)


def favorites_keyboard(items: list[dict]) -> InlineKeyboardMarkup:
    rows = [[InlineKeyboardButton(text=f"🎮 {item['game_name']}", callback_data=f"card:{item['appid']}")]
            for item in items[:20]]
    return InlineKeyboardMarkup(inline_keyboard=rows)


def format_alerts(items: list[dict], status: str) -> str:
    title = "Активные alert-ы" if status == "active" else "Сработавшие alert-ы"
    if not items:
        return f"<b>{title}</b>\nПока пусто."
    lines = [f"<b>{title}</b>"]
    for alert in items[:20]:
        game = alert.get("favorite") or {}
        line = f"• <b>{escape(str(game.get('game_name') or 'Игра'))}</b> — {price(alert.get('target_value'))}"
        if alert.get("event"):
            line += f" · найдено {price(alert['event'].get('offer_price_rub'))}"
        lines.append(line)
    return "\n".join(lines)


def alerts_keyboard(items: list[dict], status: str) -> InlineKeyboardMarkup:
    rows = []
    if status == "triggered":
        rows.extend([[InlineKeyboardButton(text=f"↻ {item['favorite']['game_name']}", callback_data=f"rearm:{item['favorite']['appid']}")]
                     for item in items[:20]])
    rows.append([InlineKeyboardButton(text="🟢 Активные", callback_data="alerts:active"),
                 InlineKeyboardButton(text="🔔 Сработавшие", callback_data="alerts:triggered")])
    return InlineKeyboardMarkup(inline_keyboard=rows)
