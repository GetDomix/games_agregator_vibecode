"""Best-effort history / favorites side-effects after price aggregation."""

from __future__ import annotations

import logging

from sqlalchemy import select
from sqlalchemy.orm import Session

from app.db_models import Favorite, PriceSnapshot, SearchHistory, User, utcnow
from app.schemas import FavoriteItem, PriceResponse

logger = logging.getLogger(__name__)


def _min_from_market(stats) -> float | None:
    mins = [k.min_price for k in (stats.by_kind or []) if k.min_price is not None]
    return min(mins) if mins else None


def save_search_history(db: Session, user: User, result: PriceResponse) -> bool:
    try:
        plati_min = _min_from_market(result.plati)
        ggsel_min = _min_from_market(result.ggsel)
        steam = result.steam
        row = SearchHistory(
            user_id=user.id,
            query=result.query,
            appid=steam.appid if steam else None,
            game_name=steam.name if steam else result.query,
            header_image=steam.header_image if steam else None,
            steam_price_rub=steam.price_rub if steam else None,
            plati_min_rub=plati_min,
            ggsel_min_rub=ggsel_min,
        )
        db.add(row)

        market_candidates = [p for p in (plati_min, ggsel_min) if p is not None]
        snap = PriceSnapshot(
            user_id=user.id,
            appid=steam.appid if steam else None,
            steam_price_rub=steam.price_rub if steam else None,
            market_min_rub=min(market_candidates) if market_candidates else None,
            source_query=result.query,
        )
        db.add(snap)

        # Keep favorite last price in sync when user re-checks
        if steam and steam.appid:
            fav = db.scalar(
                select(Favorite).where(Favorite.user_id == user.id, Favorite.appid == steam.appid)
            )
            if fav is not None and steam.price_rub is not None:
                fav.last_steam_price_rub = steam.price_rub
                fav.updated_at = utcnow()

        db.commit()
        return True
    except Exception:
        logger.exception("Failed to save search history")
        db.rollback()
        return False


def is_favorite(db: Session, user: User | None, appid: int | None) -> bool:
    if user is None or appid is None:
        return False
    row = db.scalar(select(Favorite.id).where(Favorite.user_id == user.id, Favorite.appid == appid))
    return row is not None


def favorite_to_schema(row: Favorite) -> FavoriteItem:
    below = False
    if (
        row.target_price_rub is not None
        and row.last_steam_price_rub is not None
        and row.last_steam_price_rub <= row.target_price_rub
    ):
        below = True
    return FavoriteItem(
        id=row.id,
        appid=row.appid,
        game_name=row.game_name,
        header_image=row.header_image,
        notes=row.notes,
        target_price_rub=row.target_price_rub,
        last_steam_price_rub=row.last_steam_price_rub,
        price_below_target=below,
        created_at=row.created_at,
        updated_at=row.updated_at,
    )
