from __future__ import annotations

import logging
from typing import Annotated

from fastapi import APIRouter, Depends, HTTPException, Query, Request
from sqlalchemy.orm import Session

from app.auth.deps import get_optional_user
from app.config import Settings, get_settings
from app.db import get_db
from app.db_models import User
from app.schemas import PriceResponse, SearchResponse
from app.services.aggregator import aggregate_prices, search_candidates
from app.services.persistence import is_favorite, save_search_history
from app.services.rate_limit import limiter

logger = logging.getLogger(__name__)
router = APIRouter(prefix="/api", tags=["prices"])


def _client_ip(request: Request) -> str:
    forwarded = request.headers.get("x-forwarded-for")
    if forwarded:
        return forwarded.split(",")[0].strip()
    return request.client.host if request.client else "unknown"


@router.get("/search", response_model=SearchResponse)
async def api_search(
    request: Request,
    q: str = Query(..., min_length=1, max_length=120),
    settings: Settings = Depends(get_settings),
) -> SearchResponse:
    client = request.app.state.http
    candidates = await search_candidates(client, q.strip(), settings)
    return SearchResponse(query=q.strip(), candidates=candidates)


@router.get("/prices", response_model=PriceResponse)
async def api_prices(
    request: Request,
    q: str = Query(..., min_length=1, max_length=120),
    appid: int | None = Query(None),
    settings: Settings = Depends(get_settings),
    db: Session = Depends(get_db),
    user: Annotated[User | None, Depends(get_optional_user)] = None,
) -> PriceResponse:
    if not limiter.allow(
        f"prices:{_client_ip(request)}",
        settings.rate_limit_prices_per_minute,
    ):
        raise HTTPException(status_code=429, detail="Слишком много запросов, подождите минуту")

    client = request.app.state.http
    try:
        result = await aggregate_prices(client, q.strip(), settings, appid=appid)
    except ValueError as exc:
        raise HTTPException(status_code=400, detail=str(exc)) from exc
    except Exception as exc:
        logger.exception("Price aggregation failed")
        raise HTTPException(status_code=502, detail=f"Ошибка агрегации: {exc}") from exc

    if user is not None:
        result.saved_to_history = save_search_history(db, user, result)
        steam_appid = result.steam.appid if result.steam else appid
        result.is_favorite = is_favorite(db, user, steam_appid)

    return result
