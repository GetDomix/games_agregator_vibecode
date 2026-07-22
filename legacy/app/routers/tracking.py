"""Partner / affiliate outbound click tracking."""

from __future__ import annotations

from typing import Annotated

from fastapi import APIRouter, Depends, Request
from sqlalchemy.orm import Session

from app.auth.deps import get_optional_user
from app.db import get_db
from app.db_models import PartnerClick, User
from app.schemas import PartnerClickRequest, PartnerClickResponse
from app.services.rate_limit import limiter

router = APIRouter(prefix="/api", tags=["tracking"])


def _client_ip(request: Request) -> str:
    forwarded = request.headers.get("x-forwarded-for")
    if forwarded:
        return forwarded.split(",")[0].strip()
    return request.client.host if request.client else "unknown"


@router.post("/track/click", response_model=PartnerClickResponse)
def track_partner_click(
    body: PartnerClickRequest,
    request: Request,
    db: Annotated[Session, Depends(get_db)],
    user: Annotated[User | None, Depends(get_optional_user)] = None,
) -> PartnerClickResponse:
    ip = _client_ip(request)
    # Soft anti-spam — still accept but don't flood DB
    if not limiter.allow(f"click:{ip}", 120, window_seconds=60.0):
        return PartnerClickResponse(ok=True, id=None)

    marketplace = (body.marketplace or "unknown").strip().lower()[:40]
    url = body.url.strip()[:1000]
    if not url:
        return PartnerClickResponse(ok=False, id=None)

    row = PartnerClick(
        user_id=user.id if user else None,
        marketplace=marketplace,
        url=url,
        appid=body.appid,
        query=(body.query or None)[:200] if body.query else None,
        price_rub=body.price_rub,
        client_ip=ip[:64],
    )
    db.add(row)
    db.commit()
    db.refresh(row)
    return PartnerClickResponse(ok=True, id=row.id)
