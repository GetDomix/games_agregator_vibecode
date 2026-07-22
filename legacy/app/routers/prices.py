from __future__ import annotations

import logging
from typing import Annotated

from fastapi import APIRouter, Depends, HTTPException, Query, Request
from sqlalchemy.orm import Session

from app.auth.deps import get_optional_user
from app.config import Settings, get_settings
from app.db import get_db
from app.db_models import User
from app.schemas import PriceResponse, QuotaStatusResponse, SearchResponse
from app.services.aggregator import aggregate_prices, search_candidates
from app.services.deal_score import compute_deal_score
from app.services.persistence import is_favorite, save_search_history
from app.services.quota import check_and_consume_search, get_quota_info
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
    query = q.strip()
    if not query:
        raise HTTPException(status_code=400, detail="Пустой поисковый запрос")
    client = request.app.state.http
    candidates = await search_candidates(client, query, settings)
    return SearchResponse(query=query, candidates=candidates)


@router.get("/quota", response_model=QuotaStatusResponse)
def api_quota(
    request: Request,
    settings: Settings = Depends(get_settings),
    db: Session = Depends(get_db),
    user: Annotated[User | None, Depends(get_optional_user)] = None,
) -> QuotaStatusResponse:
    info = get_quota_info(db, user=user, client_ip=_client_ip(request), settings=settings)
    return QuotaStatusResponse(quota=info)


@router.get("/prices", response_model=PriceResponse)
async def api_prices(
    request: Request,
    q: str = Query(..., min_length=1, max_length=120),
    appid: int | None = Query(None, ge=1),
    settings: Settings = Depends(get_settings),
    db: Session = Depends(get_db),
    user: Annotated[User | None, Depends(get_optional_user)] = None,
) -> PriceResponse:
    if not limiter.allow(
        f"prices:{_client_ip(request)}",
        settings.rate_limit_prices_per_minute,
    ):
        raise HTTPException(status_code=429, detail="Слишком много запросов, подождите минуту")

    query = q.strip()
    if not query:
        raise HTTPException(status_code=400, detail="Пустой поисковый запрос")

    try:
        quota = check_and_consume_search(
            db, user=user, client_ip=_client_ip(request), settings=settings
        )
    except ValueError as exc:
        raise HTTPException(status_code=429, detail=str(exc)) from exc

    client = request.app.state.http
    try:
        result = await aggregate_prices(client, query, settings, appid=appid)
    except ValueError as exc:
        raise HTTPException(status_code=400, detail=str(exc)) from exc
    except Exception as exc:
        logger.exception("Price aggregation failed")
        raise HTTPException(status_code=502, detail=f"Ошибка агрегации: {exc}") from exc

    steam_price = result.steam.price_rub if result.steam and not result.steam.is_free else None
    result.deal = compute_deal_score(steam_price, result.plati, result.ggsel)
    result.quota = quota

    if user is not None:
        result.saved_to_history = save_search_history(db, user, result)
        steam_appid = result.steam.appid if result.steam else appid
        result.is_favorite = is_favorite(db, user, steam_appid)

    return result
