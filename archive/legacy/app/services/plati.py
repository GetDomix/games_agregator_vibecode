from __future__ import annotations

import logging
from typing import Any
from urllib.parse import quote_plus

import httpx

from app.config import Settings
from app.schemas import OfferLink
from app.services.classifier import classify_plati

logger = logging.getLogger(__name__)

PLATI_SEARCH_URLS = (
    "https://plati.market/api/search.ashx",
    "https://plati.io/api/search.ashx",
)


def _partner_url(url: str, partner_id: str) -> str:
    if not partner_id:
        return url
    sep = "&" if "?" in url else "?"
    return f"{url}{sep}ai={partner_id}"


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


async def search_plati_offers(
    client: httpx.AsyncClient,
    query: str,
    settings: Settings,
) -> tuple[list[OfferLink], int, str | None]:
    """
    Search Plati.Market public Digiseller partner API.

    Docs: https://plati.market/api/?show=xml&f=7
    """
    page_size = max(1, min(settings.plati_page_size, 100))
    max_pages = max(1, settings.plati_max_pages)
    offers: list[OfferLink] = []
    total_pages_reported = 1
    last_error: str | None = None

    for page in range(1, max_pages + 1):
        params = {
            "query": query,
            "pagesize": page_size,
            "pagenum": page,
            "response": "json",
        }
        payload: dict[str, Any] | None = None

        for base in PLATI_SEARCH_URLS:
            try:
                resp = await client.get(base, params=params)
                resp.raise_for_status()
                payload = resp.json()
                break
            except Exception as exc:
                last_error = str(exc)
                logger.warning("Plati search failed %s page=%s: %s", base, page, exc)
                payload = None

        if payload is None:
            if page == 1:
                return [], 0, last_error or "Plati API unavailable"
            break

        total_pages_reported = max(1, _to_int(payload.get("Totalpages") or payload.get("totalpages") or 1))
        items = payload.get("items") or []
        if not items:
            break

        for item in items:
            price = _to_float(item.get("price_rur") if item.get("price_rur") is not None else item.get("price_rub"))
            if price is None or price <= 0:
                continue
            name = str(item.get("name") or item.get("name_eng") or "Товар")
            description = str(item.get("description") or "")
            kind = classify_plati(name, description)
            raw_url = str(item.get("url") or f"https://plati.market/itm/{item.get('id')}")
            offers.append(
                OfferLink(
                    title=name,
                    url=_partner_url(raw_url, settings.digiseller_partner_id),
                    price_rub=round(price, 2),
                    sales=_to_int(item.get("numsold")),
                    seller_name=str(item.get("seller_name") or "") or None,
                    kind=kind,
                )
            )

        if page >= total_pages_reported:
            break

    # Approximate total offers from reported pages (API doesn't return exact total)
    approx_total = total_pages_reported * page_size if offers else 0
    return offers, approx_total, None


def plati_search_page_url(query: str) -> str:
    return f"https://plati.market/search/{quote_plus(query)}"
