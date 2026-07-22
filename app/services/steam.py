from __future__ import annotations

import logging
from typing import Any

import httpx

from app.config import Settings
from app.schemas import SteamCandidate, SteamPrice

logger = logging.getLogger(__name__)

STORESEARCH_URL = "https://store.steampowered.com/api/storesearch/"
APPDETAILS_URL = "https://store.steampowered.com/api/appdetails"


def _kopecks_to_rub(value: int | float | None) -> float | None:
    if value is None:
        return None
    return round(float(value) / 100.0, 2)


def _price_from_store_item(item: dict[str, Any]) -> tuple[float | None, float | None, int, bool]:
    price = item.get("price") or {}
    if not price:
        # Free games often omit price
        return None, None, 0, False
    final = _kopecks_to_rub(price.get("final"))
    initial = _kopecks_to_rub(price.get("initial"))
    discount = 0
    if initial and final is not None and initial > 0 and final < initial:
        discount = int(round((1 - final / initial) * 100))
    return final, initial, discount, False


async def _storesearch(
    client: httpx.AsyncClient,
    query: str,
    cc: str,
    lang: str,
    available_in_ru: bool,
    limit: int,
) -> list[SteamCandidate]:
    params = {"term": query, "l": lang, "cc": cc.upper()}
    try:
        resp = await client.get(STORESEARCH_URL, params=params)
        resp.raise_for_status()
        payload = resp.json()
    except Exception as exc:
        logger.warning("Steam search failed cc=%s: %s", cc, exc)
        return []

    candidates: list[SteamCandidate] = []
    for item in payload.get("items") or []:
        item_type = item.get("type")
        if item_type not in (None, "app", "game"):
            continue
        appid = item.get("id")
        name = item.get("name")
        if not appid or not name:
            continue
        final, initial, discount, _ = _price_from_store_item(item)
        # Non-RU search prices are not RUB — hide them
        if not available_in_ru:
            final, initial, discount = None, None, 0
        candidates.append(
            SteamCandidate(
                appid=int(appid),
                name=str(name),
                tiny_image=item.get("tiny_image"),
                header_image=item.get("tiny_image"),
                price_rub=final,
                price_initial_rub=initial,
                discount_percent=discount,
                is_free=bool(final == 0 and available_in_ru),
                available_in_ru=available_in_ru,
            )
        )
        if len(candidates) >= limit:
            break
    return candidates


async def search_steam(
    client: httpx.AsyncClient,
    query: str,
    settings: Settings,
    limit: int = 8,
) -> list[SteamCandidate]:
    candidates = await _storesearch(
        client,
        query,
        cc=settings.steam_cc,
        lang=settings.steam_lang,
        available_in_ru=True,
        limit=limit,
    )
    if candidates:
        return candidates
    # Fallback: some titles are delisted in RU but still exist globally
    return await _storesearch(
        client,
        query,
        cc="us",
        lang="english",
        available_in_ru=False,
        limit=limit,
    )


async def get_steam_details(
    client: httpx.AsyncClient,
    appid: int,
    settings: Settings,
    fallback_name: str | None = None,
) -> SteamPrice:
    store_url = f"https://store.steampowered.com/app/{appid}/"

    async def _fetch(cc: str, lang: str) -> dict[str, Any] | None:
        try:
            resp = await client.get(
                APPDETAILS_URL,
                params={"appids": appid, "cc": cc, "l": lang},
            )
            resp.raise_for_status()
            payload = resp.json()
            block = payload.get(str(appid)) or {}
            if not block.get("success"):
                return None
            return block.get("data") or {}
        except Exception as exc:
            logger.warning("Steam appdetails failed appid=%s cc=%s: %s", appid, cc, exc)
            return None

    data = await _fetch(settings.steam_cc, settings.steam_lang)
    available_in_ru = data is not None
    note: str | None = None

    if data is None:
        # Game may be delisted in RU (common for some titles). Identify via US store.
        data = await _fetch("us", "english")
        if data is None:
            return SteamPrice(
                appid=appid,
                name=fallback_name or f"App {appid}",
                store_url=store_url,
                available_in_ru=False,
                note="Игра не найдена в Steam или недоступна в регионе RU.",
            )
        note = "Страница есть, но в Steam RU игра сейчас недоступна (delisted/region). Цена RU отсутствует."

    price = data.get("price_overview") or {}
    is_free = bool(data.get("is_free"))
    final = _kopecks_to_rub(price.get("final")) if price else (0.0 if is_free else None)
    initial = _kopecks_to_rub(price.get("initial")) if price else (0.0 if is_free else None)
    discount = int(price.get("discount_percent") or 0) if price else 0

    # If we fell back to US details, do not show USD as RUB.
    if not available_in_ru:
        final = None
        initial = None
        discount = 0

    header = data.get("header_image") or data.get("capsule_image")
    name = data.get("name") or fallback_name or f"App {appid}"

    return SteamPrice(
        appid=appid,
        name=name,
        header_image=header,
        store_url=store_url,
        price_rub=final,
        price_initial_rub=initial,
        discount_percent=discount,
        is_free=is_free and available_in_ru,
        available_in_ru=available_in_ru,
        currency="RUB",
        note=note,
    )


def pick_best_candidate(candidates: list[SteamCandidate], query: str) -> SteamCandidate | None:
    if not candidates:
        return None
    q = query.strip().lower()
    exact = [c for c in candidates if c.name.lower() == q]
    if exact:
        return exact[0]
    starts = [c for c in candidates if c.name.lower().startswith(q)]
    if starts:
        return starts[0]
    contains = [c for c in candidates if q in c.name.lower()]
    if contains:
        return contains[0]
    return candidates[0]
