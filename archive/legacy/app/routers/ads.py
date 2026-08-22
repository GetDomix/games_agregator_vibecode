from __future__ import annotations

from fastapi import APIRouter

from app.config import get_settings
from app.schemas import AdsConfigResponse
from app.services.ads import build_ads_config

router = APIRouter(prefix="/api", tags=["ads"])


@router.get("/ads/config", response_model=AdsConfigResponse)
async def ads_config() -> AdsConfigResponse:
    return build_ads_config(get_settings())
