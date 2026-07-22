from __future__ import annotations

from typing import Annotated

from fastapi import APIRouter, Depends, HTTPException, Query, Response
from sqlalchemy import delete, func, select
from sqlalchemy.orm import Session

from app.auth.deps import get_current_user
from app.db import get_db
from app.db_models import SearchHistory, User
from app.schemas import HistoryItem, HistoryListResponse

router = APIRouter(prefix="/api/me/history", tags=["history"])


@router.get("", response_model=HistoryListResponse)
def list_history(
    user: Annotated[User, Depends(get_current_user)],
    db: Annotated[Session, Depends(get_db)],
    limit: int = Query(50, ge=1, le=200),
) -> HistoryListResponse:
    total = db.scalar(
        select(func.count()).select_from(SearchHistory).where(SearchHistory.user_id == user.id)
    ) or 0
    rows = db.scalars(
        select(SearchHistory)
        .where(SearchHistory.user_id == user.id)
        .order_by(SearchHistory.created_at.desc())
        .limit(limit)
    ).all()
    return HistoryListResponse(
        items=[HistoryItem.model_validate(r) for r in rows],
        total=int(total),
    )


@router.delete("")
def clear_history(
    user: Annotated[User, Depends(get_current_user)],
    db: Annotated[Session, Depends(get_db)],
) -> Response:
    db.execute(delete(SearchHistory).where(SearchHistory.user_id == user.id))
    db.commit()
    return Response(status_code=204)


@router.delete("/{item_id}")
def delete_history_item(
    item_id: int,
    user: Annotated[User, Depends(get_current_user)],
    db: Annotated[Session, Depends(get_db)],
) -> Response:
    row = db.get(SearchHistory, item_id)
    if row is None or row.user_id != user.id:
        raise HTTPException(status_code=404, detail="Запись не найдена")
    db.delete(row)
    db.commit()
    return Response(status_code=204)
