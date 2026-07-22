from __future__ import annotations

from datetime import timedelta
from typing import Annotated

from fastapi import APIRouter, Depends, Query, Request
from sqlalchemy import desc, func, select
from sqlalchemy.orm import Session

from app.auth.deps import get_current_user, get_optional_user
from app.config import Settings, get_settings
from app.db import get_db
from app.db_models import Favorite, SearchHistory, User, utcnow
from app.schemas import (
    DashboardResponse,
    HotDealItem,
    HotDealsResponse,
    HistoryItem,
    PopularItem,
    TrendsResponse,
    UserPublic,
)
from app.services.persistence import favorite_to_schema
from app.services.quota import get_quota_info

router = APIRouter(tags=["dashboard"])

# Cold-start seed when community history is empty
SEED_POPULAR = [
    PopularItem(query="Hades", game_name="Hades", appid=1145360, count=0),
    PopularItem(query="Cyberpunk 2077", game_name="Cyberpunk 2077", appid=1091500, count=0),
    PopularItem(query="Elden Ring", game_name="ELDEN RING", appid=1245620, count=0),
    PopularItem(query="Stardew Valley", game_name="Stardew Valley", appid=413150, count=0),
    PopularItem(query="GTA V", game_name="Grand Theft Auto V", appid=271590, count=0),
    PopularItem(query="Hollow Knight", game_name="Hollow Knight", appid=367520, count=0),
    PopularItem(query="Balatro", game_name="Balatro", appid=2379780, count=0),
    PopularItem(query="Palworld", game_name="Palworld", appid=1623730, count=0),
]

SEED_HOT = [
    HotDealItem(
        query="Hades",
        game_name="Hades",
        appid=1145360,
        steam_price_rub=999,
        market_min_rub=649,
        savings_percent=35.0,
        savings_rub=350,
    ),
    HotDealItem(
        query="Balatro",
        game_name="Balatro",
        appid=2379780,
        steam_price_rub=450,
        market_min_rub=320,
        savings_percent=28.9,
        savings_rub=130,
    ),
    HotDealItem(
        query="Stardew Valley",
        game_name="Stardew Valley",
        appid=413150,
        steam_price_rub=349,
        market_min_rub=249,
        savings_percent=28.7,
        savings_rub=100,
    ),
    HotDealItem(
        query="Hollow Knight",
        game_name="Hollow Knight",
        appid=367520,
        steam_price_rub=499,
        market_min_rub=349,
        savings_percent=30.1,
        savings_rub=150,
    ),
]


def _client_ip(request: Request) -> str:
    forwarded = request.headers.get("x-forwarded-for")
    if forwarded:
        return forwarded.split(",")[0].strip()
    return request.client.host if request.client else "unknown"


def _market_min(plati: float | None, ggsel: float | None) -> float | None:
    vals = [v for v in (plati, ggsel) if v is not None]
    return min(vals) if vals else None


@router.get("/api/me/dashboard", response_model=DashboardResponse)
def dashboard(
    request: Request,
    user: Annotated[User, Depends(get_current_user)],
    db: Annotated[Session, Depends(get_db)],
    settings: Annotated[Settings, Depends(get_settings)],
) -> DashboardResponse:
    recent = db.scalars(
        select(SearchHistory)
        .where(SearchHistory.user_id == user.id)
        .order_by(SearchHistory.created_at.desc())
        .limit(8)
    ).all()

    favs = db.scalars(
        select(Favorite).where(Favorite.user_id == user.id).order_by(Favorite.updated_at.desc()).limit(12)
    ).all()
    fav_items = [favorite_to_schema(f) for f in favs]
    # All price hits (not only preview window)
    all_favs = db.scalars(select(Favorite).where(Favorite.user_id == user.id)).all()
    price_hits = [favorite_to_schema(f) for f in all_favs if favorite_to_schema(f).price_below_target]
    fav_count = db.scalar(
        select(func.count()).select_from(Favorite).where(Favorite.user_id == user.id)
    ) or 0

    week_ago = utcnow() - timedelta(days=7)
    searches_total = db.scalar(
        select(func.count()).select_from(SearchHistory).where(SearchHistory.user_id == user.id)
    ) or 0
    searches_week = db.scalar(
        select(func.count())
        .select_from(SearchHistory)
        .where(SearchHistory.user_id == user.id, SearchHistory.created_at >= week_ago)
    ) or 0

    alerts = len(price_hits)
    quota = get_quota_info(db, user=user, client_ip=_client_ip(request), settings=settings)

    ctas: list[str] = []
    if alerts:
        ctas.append(f"🔥 {alerts} игр(а) на цели или ниже — пора покупать!")
    if fav_count == 0:
        ctas.append("Добавь игру в избранное — цель по цене и алерт «на цели».")
    elif fav_count < 3:
        ctas.append(f"Ещё {3 - fav_count} в избранном — и кабинет заживёт.")
    if searches_total >= 3 and fav_count == 0:
        ctas.append("Ты уже сравнивал цены — сохрани избранное, чтобы не потерять.")
    if quota.remaining <= 3:
        ctas.append(f"Осталось {quota.remaining} поисков сегодня — обновляй избранное кнопкой «Обновить цены».")
    if not ctas:
        ctas.append("Проверь цены на избранное — Steam и маркетплейсы обновляются вживую.")

    return DashboardResponse(
        user=UserPublic.model_validate(user),
        recent_history=[HistoryItem.model_validate(r) for r in recent],
        favorites_preview=fav_items,
        price_hits=price_hits,
        favorites_count=int(fav_count),
        searches_total=int(searches_total),
        searches_this_week=int(searches_week),
        alerts_count=alerts,
        ctas=ctas,
        quota=quota,
    )


@router.get("/api/trends/popular", response_model=TrendsResponse)
def popular_trends(
    db: Annotated[Session, Depends(get_db)],
    limit: int = Query(10, ge=1, le=30),
) -> TrendsResponse:
    week_ago = utcnow() - timedelta(days=7)
    rows = db.execute(
        select(
            SearchHistory.query,
            func.count().label("cnt"),
            func.max(SearchHistory.appid).label("appid"),
            func.max(SearchHistory.game_name).label("game_name"),
            func.max(SearchHistory.header_image).label("header_image"),
            func.avg(SearchHistory.steam_price_rub).label("avg_steam"),
            func.avg(SearchHistory.plati_min_rub).label("avg_plati"),
            func.avg(SearchHistory.ggsel_min_rub).label("avg_ggsel"),
        )
        .where(SearchHistory.created_at >= week_ago)
        .group_by(SearchHistory.query)
        .order_by(desc("cnt"))
        .limit(limit)
    ).all()

    if not rows:
        return TrendsResponse(items=SEED_POPULAR[:limit], source="seed")

    items: list[PopularItem] = []
    for r in rows:
        steam = float(r.avg_steam) if r.avg_steam is not None else None
        market = _market_min(
            float(r.avg_plati) if r.avg_plati is not None else None,
            float(r.avg_ggsel) if r.avg_ggsel is not None else None,
        )
        savings = None
        if steam and steam > 0 and market is not None and market < steam:
            savings = round((steam - market) / steam * 100, 1)
        items.append(
            PopularItem(
                query=r.query,
                count=int(r.cnt),
                appid=r.appid,
                game_name=r.game_name,
                header_image=r.header_image,
                steam_price_rub=round(steam, 0) if steam is not None else None,
                market_min_rub=round(market, 0) if market is not None else None,
                savings_percent=savings,
            )
        )
    return TrendsResponse(items=items, source="community")


@router.get("/api/trends/hot-deals", response_model=HotDealsResponse)
def hot_deals(
    db: Annotated[Session, Depends(get_db)],
    limit: int = Query(8, ge=1, le=30),
) -> HotDealsResponse:
    """Community digests: games where market min was meaningfully below Steam recently."""
    week_ago = utcnow() - timedelta(days=7)
    rows = db.scalars(
        select(SearchHistory)
        .where(
            SearchHistory.created_at >= week_ago,
            SearchHistory.steam_price_rub.is_not(None),
        )
        .order_by(SearchHistory.created_at.desc())
        .limit(200)
    ).all()

    best: dict[str, HotDealItem] = {}
    for r in rows:
        market = _market_min(r.plati_min_rub, r.ggsel_min_rub)
        steam = r.steam_price_rub
        if steam is None or steam <= 0 or market is None or market >= steam:
            continue
        savings_rub = round(steam - market, 2)
        savings_pct = round(savings_rub / steam * 100, 1)
        if savings_pct < 8:
            continue
        key = str(r.appid or r.query).lower()
        item = HotDealItem(
            query=r.query,
            appid=r.appid,
            game_name=r.game_name or r.query,
            header_image=r.header_image,
            steam_price_rub=steam,
            market_min_rub=market,
            savings_percent=savings_pct,
            savings_rub=savings_rub,
        )
        prev = best.get(key)
        if prev is None or (prev.savings_percent or 0) < savings_pct:
            best[key] = item

    items = sorted(best.values(), key=lambda x: x.savings_percent or 0, reverse=True)[:limit]
    if not items:
        return HotDealsResponse(items=SEED_HOT[:limit], source="seed")
    return HotDealsResponse(items=items, source="community")
