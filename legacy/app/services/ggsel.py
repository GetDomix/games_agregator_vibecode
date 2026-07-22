from __future__ import annotations

import logging
from typing import Any
from urllib.parse import quote

import httpx

from app.config import Settings
from app.schemas import OfferLink
from app.services.classifier import classify_ggsel

logger = logging.getLogger(__name__)

GGSEL_API = "https://api.ggsel.com"
GGSEL_QUERY_URL = f"{GGSEL_API}/elastic/goods/query"


def _to_float(value: Any) -> float | None:
    if value is None or value == "":
        return None
    try:
        return float(value)
    except (TypeError, ValueError):
        return None


def _to_int(value: Any) -> int:
    try:
        return int(value or 0)
    except (TypeError, ValueError):
        return 0


def _partner_url(url: str, partner_id: str) -> str:
    if not partner_id:
        return url
    sep = "&" if "?" in url else "?"
    return f"{url}{sep}ai={partner_id}"


def _build_offer_url(item: dict[str, Any], partner_id: str) -> str:
    slug = item.get("url")
    goods_id = item.get("id_goods") or item.get("id")
    if slug:
        raw = f"https://ggsel.net/catalog/product/{slug}"
    elif goods_id:
        raw = f"https://ggsel.net/catalog/product/{goods_id}"
    else:
        raw = "https://ggsel.net/"
    return _partner_url(raw, partner_id)


async def search_ggsel_offers(
    client: httpx.AsyncClient,
    query: str,
    settings: Settings,
) -> tuple[list[OfferLink], int, str | None]:
    """
    Search GGsel via public elastic API used by the website frontend.
    POST https://api.ggsel.com/elastic/goods/query
    """
    limit = max(1, min(settings.ggsel_limit, 200))
    payload = {
        "search_term": query,
        "lang": "ru",
        "limit": limit,
        "is_russian_ip": True,
    }
    headers = {
        "Content-Type": "application/json",
        "Origin": "https://ggsel.net",
        "Referer": "https://ggsel.net/",
        "Accept": "application/json",
    }

    try:
        resp = await client.post(GGSEL_QUERY_URL, json=payload, headers=headers)
        resp.raise_for_status()
        data = resp.json()
    except Exception as exc:
        logger.warning("GGsel search failed: %s", exc)
        return [], 0, str(exc)

    body = data.get("data") or {}
    if isinstance(body, list):
        items = body
        total = len(items)
    else:
        items = body.get("items") or []
        total = _to_int(body.get("total") or body.get("total_count") or len(items))

    offers: list[OfferLink] = []
    for item in items:
        if item.get("is_active") is False:
            continue
        # price_wmr is RUB on GGsel API
        price = _to_float(item.get("price_wmr") if item.get("price_wmr") is not None else item.get("price_rub"))
        if price is None or price <= 0:
            continue
        name = str(item.get("name") or "Товар")
        search_title = str(item.get("search_title") or "")
        content_type_id = item.get("content_type_id")
        try:
            content_type_id = int(content_type_id) if content_type_id is not None else None
        except (TypeError, ValueError):
            content_type_id = None

        kind = classify_ggsel(content_type_id, name, search_title)
        offers.append(
            OfferLink(
                title=name if not search_title else f"{name} — {search_title}",
                url=_build_offer_url(item, settings.digiseller_partner_id),
                price_rub=round(price, 2),
                sales=_to_int(item.get("cnt_sell")),
                seller_name=str(item.get("seller_name") or "") or None,
                kind=kind,
            )
        )

    return offers, total or len(offers), None


def ggsel_search_page_url(query: str) -> str:
    return f"https://ggsel.net/catalog?query={quote(query)}"
