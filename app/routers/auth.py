from __future__ import annotations

from typing import Annotated

from fastapi import APIRouter, Depends, HTTPException, Request, status
from sqlalchemy import select
from sqlalchemy.orm import Session

from app.auth.deps import get_current_user
from app.auth.security import create_access_token, hash_password, verify_password
from app.config import Settings, get_settings
from app.db import get_db
from app.db_models import User, utcnow
from app.schemas import (
    LoginRequest,
    ProfileUpdateRequest,
    RegisterRequest,
    TokenResponse,
    UserPublic,
)
from app.services.rate_limit import limiter

router = APIRouter(prefix="/api/auth", tags=["auth"])


def _client_ip(request: Request) -> str:
    forwarded = request.headers.get("x-forwarded-for")
    if forwarded:
        return forwarded.split(",")[0].strip()
    return request.client.host if request.client else "unknown"


@router.post("/register", response_model=TokenResponse, status_code=status.HTTP_201_CREATED)
def register(
    body: RegisterRequest,
    request: Request,
    db: Annotated[Session, Depends(get_db)],
    settings: Annotated[Settings, Depends(get_settings)],
) -> TokenResponse:
    if not limiter.allow(f"reg:{_client_ip(request)}", settings.rate_limit_login_per_minute):
        raise HTTPException(status_code=429, detail="Слишком много попыток, подождите минуту")

    email = body.email.lower().strip()
    exists = db.scalar(select(User).where(User.email == email))
    if exists:
        raise HTTPException(status_code=400, detail="Email уже зарегистрирован")

    name = (body.display_name or email.split("@")[0]).strip()[:80] or "Игрок"
    user = User(
        email=email,
        password_hash=hash_password(body.password),
        display_name=name,
        last_login_at=utcnow(),
    )
    db.add(user)
    db.commit()
    db.refresh(user)

    token = create_access_token(user_id=user.id, settings=settings)
    return TokenResponse(access_token=token, user=UserPublic.model_validate(user))


@router.post("/login", response_model=TokenResponse)
def login(
    body: LoginRequest,
    request: Request,
    db: Annotated[Session, Depends(get_db)],
    settings: Annotated[Settings, Depends(get_settings)],
) -> TokenResponse:
    if not limiter.allow(f"login:{_client_ip(request)}", settings.rate_limit_login_per_minute):
        raise HTTPException(status_code=429, detail="Слишком много попыток, подождите минуту")

    email = body.email.lower().strip()
    user = db.scalar(select(User).where(User.email == email))
    if user is None or not verify_password(body.password, user.password_hash):
        raise HTTPException(status_code=401, detail="Неверный email или пароль")

    user.last_login_at = utcnow()
    db.commit()
    db.refresh(user)

    token = create_access_token(user_id=user.id, settings=settings)
    return TokenResponse(access_token=token, user=UserPublic.model_validate(user))


@router.get("/me", response_model=UserPublic)
def me(user: Annotated[User, Depends(get_current_user)]) -> UserPublic:
    return UserPublic.model_validate(user)


@router.patch("/me", response_model=UserPublic)
def update_me(
    body: ProfileUpdateRequest,
    user: Annotated[User, Depends(get_current_user)],
    db: Annotated[Session, Depends(get_db)],
) -> UserPublic:
    user.display_name = body.display_name.strip()[:80]
    db.commit()
    db.refresh(user)
    return UserPublic.model_validate(user)
