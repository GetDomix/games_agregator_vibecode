from __future__ import annotations

import re

from app.schemas import ProductKind

# GGsel content_type_id → product kind
# Source: https://api.ggsel.com/main/content-types?lang=ru
GGSEL_CONTENT_TYPE_MAP: dict[int, ProductKind] = {
    1: ProductKind.ACCOUNT,  # Аккаунты
    25: ProductKind.RENT,  # Аренда аккаунтов
    29: ProductKind.ACCOUNT,  # Рандом аккаунт
    31: ProductKind.ACCOUNT,  # Оффлайн аккаунты
    48: ProductKind.GIFT,  # Гифты
    56: ProductKind.GIFT,  # Гифты (китайский регион)
    2: ProductKind.KEY,  # Ключи
    30: ProductKind.KEY,  # Рандом ключи
    54: ProductKind.KEY,  # Покупка на ваш аккаунт
}

# Note: do not use \b after Cyrillic stems — "аренд" + "а" is still one word,
# so a trailing \b after the stem never matches.
_RENT_RE = re.compile(r"(?i)(аренд\w*|rent(?:al|s)?|lease)")
_GIFT_RE = re.compile(r"(?i)(гифт\w*|gift\w*|подар\w*)")
_ACCOUNT_RE = re.compile(
    r"(?i)(акк(?:аунт)?\w*|account\w*|оффлайн|offline|shared|общий\s*акк)"
)
_KEY_RE = re.compile(
    r"(?i)(ключ\w*|keys?|cd[\s-]?keys?|steam\s*keys?|gog\s*keys?|лиценз\w*)"
)


def classify_from_text(name: str, *extra: str) -> ProductKind:
    """Heuristic classification by offer title/description keywords."""
    text = " ".join(part for part in (name, *extra) if part).lower()
    if not text:
        return ProductKind.OTHER

    # More specific first
    if _RENT_RE.search(text):
        return ProductKind.RENT
    if _GIFT_RE.search(text):
        return ProductKind.GIFT
    if _ACCOUNT_RE.search(text):
        return ProductKind.ACCOUNT
    if _KEY_RE.search(text):
        return ProductKind.KEY
    return ProductKind.OTHER


def classify_ggsel(content_type_id: int | None, name: str, search_title: str = "") -> ProductKind:
    if content_type_id is not None and content_type_id in GGSEL_CONTENT_TYPE_MAP:
        return GGSEL_CONTENT_TYPE_MAP[content_type_id]
    return classify_from_text(name, search_title)


def classify_plati(name: str, description: str = "") -> ProductKind:
    return classify_from_text(name, description)
