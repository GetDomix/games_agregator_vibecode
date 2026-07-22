from __future__ import annotations

from typing import Annotated

from fastapi import APIRouter, Depends, HTTPException, Response, status
from sqlalchemy import func, select
from sqlalchemy.orm import Session

from app.auth.deps import get_current_user
from app.db import get_db
from app.db_models import Favorite, User, utcnow
from app.schemas import FavoriteCreate, FavoriteItem, FavoritesListResponse, FavoriteUpdate
from app.services.persistence import favorite_to_schema

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
    return FavoritesListResponse(items=items, total=len(items))


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
