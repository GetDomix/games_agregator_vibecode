from __future__ import annotations

import asyncio
import logging
from collections import defaultdict

import httpx

from app.config import Settings
from app.schemas import (
    PRODUCT_KIND_LABELS,
    KindStats,
    MarketplaceStats,
    OfferLink,
    PriceResponse,
    ProductKind,
    SteamCandidate,
)
from app.services.ggsel import search_ggsel_offers
from app.services.plati import search_plati_offers
from app.services.steam import get_steam_details, pick_best_candidate, search_steam

logger = logging.getLogger(__name__)

# Display order for kinds user cares about
KIND_ORDER = [
    ProductKind.KEY,
    ProductKind.GIFT,
    ProductKind.ACCOUNT,
    ProductKind.RENT,
    ProductKind.OTHER,
]


def _aggregate_by_kind(offers: list[OfferLink]) -> list[KindStats]:
    grouped: dict[ProductKind, list[OfferLink]] = defaultdict(list)
    for offer in offers:
        grouped[offer.kind].append(offer)

    stats: list[KindStats] = []
    for kind in KIND_ORDER:
        bucket = grouped.get(kind) or []
        if not bucket:
            continue
        prices = [o.price_rub for o in bucket]
        cheapest = min(bucket, key=lambda o: o.price_rub)
        popular = max(bucket, key=lambda o: (o.sales, -o.price_rub))
        stats.append(
            KindStats(
                kind=kind,
                label=PRODUCT_KIND_LABELS[kind],
                count=len(bucket),
                min_price=round(min(prices), 2),
                avg_price=round(sum(prices) / len(prices), 2),
                popular=popular,
                cheapest=cheapest,
            )
        )
    return stats


def _marketplace_stats(
    marketplace: str,
    label: str,
    offers: list[OfferLink],
    total_offers: int,
    error: str | None,
) -> MarketplaceStats:
    return MarketplaceStats(
        marketplace=marketplace,
        label=label,
        total_offers=total_offers or len(offers),
        scanned_offers=len(offers),
        by_kind=_aggregate_by_kind(offers) if not error else [],
        error=error,
    )


async def search_candidates(
    client: httpx.AsyncClient,
    query: str,
    settings: Settings,
) -> list[SteamCandidate]:
    return await search_steam(client, query, settings)


async def aggregate_prices(
    client: httpx.AsyncClient,
    query: str,
    settings: Settings,
    appid: int | None = None,
) -> PriceResponse:
    warnings: list[str] = []
    q = query.strip()
    if not q:
        raise ValueError("Пустой поисковый запрос")

    candidates = await search_steam(client, q, settings)
    selected_name = q
    steam = None

    if appid is not None:
        match = next((c for c in candidates if c.appid == appid), None)
        steam = await get_steam_details(
            client,
            appid,
            settings,
            fallback_name=match.name if match else q,
        )
        selected_name = steam.name
    else:
        best = pick_best_candidate(candidates, q)
        if best:
            steam = await get_steam_details(client, best.appid, settings, fallback_name=best.name)
            selected_name = steam.name
        else:
            warnings.append(
                "Steam не нашёл игру по запросу (возможно, delisted в RU). "
                "Ищем офферы на маркетплейсах по введённому названию."
            )

    # Prefer official Steam title for marketplace search relevance
    market_query = selected_name or q

    plati_task = search_plati_offers(client, market_query, settings)
    ggsel_task = search_ggsel_offers(client, market_query, settings)
    (plati_offers, plati_total, plati_err), (ggsel_offers, ggsel_total, ggsel_err) = await asyncio.gather(
        plati_task,
        ggsel_task,
    )

    if plati_err:
        warnings.append(f"Plati: {plati_err}")
    if ggsel_err:
        warnings.append(f"GGsel: {ggsel_err}")

    if steam and not steam.available_in_ru:
        warnings.append(steam.note or "Игра недоступна в Steam RU.")

    return PriceResponse(
        query=q,
        steam=steam,
        candidates=candidates,
        plati=_marketplace_stats("plati", "Plati.Market", plati_offers, plati_total, plati_err),
        ggsel=_marketplace_stats("ggsel", "GGsel", ggsel_offers, ggsel_total, ggsel_err),
        warnings=warnings,
    )
