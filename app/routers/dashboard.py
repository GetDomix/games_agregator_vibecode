from __future__ import annotations

from datetime import timedelta
from typing import Annotated

from fastapi import APIRouter, Depends, Query
from sqlalchemy import desc, func, select
from sqlalchemy.orm import Session

from app.auth.deps import get_current_user
from app.db import get_db
from app.db_models import Favorite, SearchHistory, User, utcnow
from app.schemas import (
    DashboardResponse,
    FavoriteItem,
    HistoryItem,
    PopularItem,
    TrendsResponse,
    UserPublic,
)
from app.services.persistence import favorite_to_schema

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


@router.get("/api/me/dashboard", response_model=DashboardResponse)
def dashboard(
    user: Annotated[User, Depends(get_current_user)],
    db: Annotated[Session, Depends(get_db)],
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

    alerts = sum(1 for f in fav_items if f.price_below_target)

    ctas: list[str] = []
    if fav_count == 0:
        ctas.append("Добавь игру в избранное — сохрани лучшую цену и цель.")
    elif fav_count < 3:
        ctas.append(f"Ещё {3 - fav_count} в избранном — и кабинет заживёт.")
    if searches_total >= 3 and fav_count == 0:
        ctas.append("Ты уже сравнивал цены — сохрани избранное, чтобы не потерять.")
    if alerts:
        ctas.append(f"🔥 {alerts} игр(а) на цели или ниже — загляни в избранное.")
    if not ctas:
        ctas.append("Проверь цены на избранное — Steam и маркетплейсы обновляются вживую.")

    return DashboardResponse(
        user=UserPublic.model_validate(user),
        recent_history=[HistoryItem.model_validate(r) for r in recent],
        favorites_preview=fav_items,
        favorites_count=int(fav_count),
        searches_total=int(searches_total),
        searches_this_week=int(searches_week),
        alerts_count=alerts,
        ctas=ctas,
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
        )
        .where(SearchHistory.created_at >= week_ago)
        .group_by(SearchHistory.query)
        .order_by(desc("cnt"))
        .limit(limit)
    ).all()

    if not rows:
        return TrendsResponse(items=SEED_POPULAR[:limit], source="seed")

    items = [
        PopularItem(
            query=r.query,
            count=int(r.cnt),
            appid=r.appid,
            game_name=r.game_name,
            header_image=r.header_image,
        )
        for r in rows
    ]
    return TrendsResponse(items=items, source="community")
