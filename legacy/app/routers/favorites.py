from __future__ import annotations

import logging
from typing import Annotated

from fastapi import APIRouter, Depends, HTTPException, Query, Request, Response, status
from sqlalchemy import func, select
from sqlalchemy.orm import Session

from app.auth.deps import get_current_user
from app.config import Settings, get_settings
from app.db import get_db
from app.db_models import Favorite, User, utcnow
from app.schemas import (
    FavoriteCreate,
    FavoriteItem,
    FavoritesListResponse,
    FavoriteUpdate,
    WatchlistRefreshItem,
    WatchlistRefreshResponse,
)
from app.services.aggregator import aggregate_prices
from app.services.deal_score import compute_deal_score
from app.services.persistence import favorite_to_schema
from app.services.rate_limit import limiter

logger = logging.getLogger(__name__)
router = APIRouter(prefix="/api/me/favorites", tags=["favorites"])


@router.get("", response_model=FavoritesListResponse)
def list_favorites(
    user: Annotated[User, Depends(get_current_user)],
    db: Annotated[Session, Depends(get_db)],
) -> FavoritesListResponse:
    rows = db.scalars(
        select(Favorite).where(Favorite.user_id == user.id).order_by(Favorite.updated_at.desc())
    ).all()
    items = [favorite_to_schema(r) for r in rows]
    hits = [i for i in items if i.price_below_target]
    return FavoritesListResponse(items=items, total=len(items), price_hits=hits)


@router.post("", response_model=FavoriteItem, status_code=status.HTTP_201_CREATED)
def add_favorite(
    body: FavoriteCreate,
    user: Annotated[User, Depends(get_current_user)],
    db: Annotated[Session, Depends(get_db)],
) -> FavoriteItem:
    existing = db.scalar(
        select(Favorite).where(Favorite.user_id == user.id, Favorite.appid == body.appid)
    )
    if existing:
        existing.game_name = body.game_name
        existing.header_image = body.header_image or existing.header_image
        if body.target_price_rub is not None:
            existing.target_price_rub = body.target_price_rub
        if body.notes is not None:
            existing.notes = body.notes
        if body.last_steam_price_rub is not None:
            existing.last_steam_price_rub = body.last_steam_price_rub
        existing.updated_at = utcnow()
        db.commit()
        db.refresh(existing)
        return favorite_to_schema(existing)

    # soft cap
    count = db.scalar(
        select(func.count()).select_from(Favorite).where(Favorite.user_id == user.id)
    ) or 0
    if count >= 200:
        raise HTTPException(status_code=400, detail="Лимит избранного: 200 игр")

    row = Favorite(
        user_id=user.id,
        appid=body.appid,
        game_name=body.game_name[:200],
        header_image=body.header_image,
        notes=body.notes,
        target_price_rub=body.target_price_rub,
        last_steam_price_rub=body.last_steam_price_rub,
    )
    db.add(row)
    db.commit()
    db.refresh(row)
    return favorite_to_schema(row)


@router.patch("/{appid}", response_model=FavoriteItem)
def update_favorite(
    appid: int,
    body: FavoriteUpdate,
    user: Annotated[User, Depends(get_current_user)],
    db: Annotated[Session, Depends(get_db)],
) -> FavoriteItem:
    row = db.scalar(select(Favorite).where(Favorite.user_id == user.id, Favorite.appid == appid))
    if row is None:
        raise HTTPException(status_code=404, detail="Игра не в избранном")
    fields = body.model_fields_set
    if "target_price_rub" in fields:
        row.target_price_rub = body.target_price_rub
    if "notes" in fields:
        row.notes = body.notes
    if "last_steam_price_rub" in fields:
        row.last_steam_price_rub = body.last_steam_price_rub
    row.updated_at = utcnow()
    db.commit()
    db.refresh(row)
    return favorite_to_schema(row)


@router.delete("/{appid}")
def remove_favorite(
    appid: int,
    user: Annotated[User, Depends(get_current_user)],
    db: Annotated[Session, Depends(get_db)],
) -> Response:
    row = db.scalar(select(Favorite).where(Favorite.user_id == user.id, Favorite.appid == appid))
    if row is None:
        raise HTTPException(status_code=404, detail="Игра не в избранном")
    db.delete(row)
    db.commit()
    return Response(status_code=204)


@router.post("/refresh", response_model=WatchlistRefreshResponse)
async def refresh_watchlist(
    request: Request,
    user: Annotated[User, Depends(get_current_user)],
    db: Annotated[Session, Depends(get_db)],
    settings: Annotated[Settings, Depends(get_settings)],
    limit: int = Query(5, ge=1, le=5),
) -> WatchlistRefreshResponse:
    """Re-fetch /api/prices-equivalent data for up to 5 favorites (rate-limited)."""
    max_n = min(limit, settings.watchlist_refresh_max)
    if not limiter.allow(
        f"watchlist-refresh:{user.id}",
        settings.rate_limit_watchlist_refresh_per_hour,
        window_seconds=3600.0,
    ):
        raise HTTPException(
            status_code=429,
            detail="Обновление избранного доступно несколько раз в час. Подожди и попробуй снова.",
        )

    rows = list(
        db.scalars(
            select(Favorite)
            .where(Favorite.user_id == user.id)
            .order_by(Favorite.updated_at.desc())
            .limit(max_n)
        ).all()
    )
    if not rows:
        return WatchlistRefreshResponse(
            refreshed=[],
            skipped=0,
            message="В избранном пока пусто — добавь игру с карточки Steam.",
        )

    client = request.app.state.http
    refreshed: list[WatchlistRefreshItem] = []

    for fav in rows:
        try:
            result = await aggregate_prices(
                client, fav.game_name, settings, appid=fav.appid
            )
            steam_price = None
            if result.steam and result.steam.price_rub is not None:
                steam_price = result.steam.price_rub
                fav.last_steam_price_rub = steam_price
                if result.steam.header_image:
                    fav.header_image = result.steam.header_image
                if result.steam.name:
                    fav.game_name = result.steam.name[:200]
            fav.updated_at = utcnow()
            deal = compute_deal_score(
                steam_price if steam_price is not None else fav.last_steam_price_rub,
                result.plati,
                result.ggsel,
            )
            item = favorite_to_schema(fav)
            refreshed.append(
                WatchlistRefreshItem(
                    appid=fav.appid,
                    game_name=fav.game_name,
                    ok=True,
                    last_steam_price_rub=item.last_steam_price_rub,
                    target_price_rub=item.target_price_rub,
                    price_below_target=item.price_below_target,
                    market_min_rub=deal.market_min_rub,
                )
            )
        except Exception as exc:
            logger.warning("Watchlist refresh failed appid=%s: %s", fav.appid, exc)
            refreshed.append(
                WatchlistRefreshItem(
                    appid=fav.appid,
                    game_name=fav.game_name,
                    ok=False,
                    last_steam_price_rub=fav.last_steam_price_rub,
                    target_price_rub=fav.target_price_rub,
                    price_below_target=False,
                    error=str(exc)[:200],
                )
            )

    db.commit()
    hits = sum(1 for r in refreshed if r.price_below_target)
    msg = f"Обновлено {sum(1 for r in refreshed if r.ok)} из {len(refreshed)}."
    if hits:
        msg += f" 🔥 {hits} на цели или ниже!"
    return WatchlistRefreshResponse(refreshed=refreshed, skipped=0, message=msg)
