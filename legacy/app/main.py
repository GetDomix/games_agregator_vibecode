from __future__ import annotations

import logging
from contextlib import asynccontextmanager
from pathlib import Path

from fastapi import FastAPI, HTTPException
from fastapi.middleware.cors import CORSMiddleware
from fastapi.responses import FileResponse
from fastapi.staticfiles import StaticFiles
from starlette.middleware.base import BaseHTTPMiddleware
from starlette.requests import Request
from starlette.responses import Response

from app.config import get_settings
from app.db import check_db, init_db
from app.routers import ads, auth, dashboard, favorites, history, prices, tracking
from app.schemas import HealthResponse
from app.services.http_client import create_client

logging.basicConfig(level=logging.INFO)
logger = logging.getLogger(__name__)

STATIC_DIR = Path(__file__).resolve().parent / "static"


class SecurityHeadersMiddleware(BaseHTTPMiddleware):
    async def dispatch(self, request: Request, call_next) -> Response:
        response = await call_next(request)
        response.headers.setdefault("X-Content-Type-Options", "nosniff")
        response.headers.setdefault("X-Frame-Options", "DENY")
        response.headers.setdefault("Referrer-Policy", "strict-origin-when-cross-origin")
        response.headers.setdefault("Permissions-Policy", "geolocation=(), microphone=(), camera=()")
        return response


@asynccontextmanager
async def lifespan(app: FastAPI):
    settings = get_settings()
    if settings.app_env == "production" and settings.secret_key.startswith("dev-"):
        logger.error("SECRET_KEY is still the default — set a strong secret before production traffic!")
    init_db()
    logger.info("Database ready (%s)", settings.database_url.split("://")[0])
    app.state.http = create_client()
    logger.info("HTTP client ready")
    try:
        yield
    finally:
        await app.state.http.aclose()
        logger.info("HTTP client closed")


def create_app() -> FastAPI:
    settings = get_settings()
    application = FastAPI(
        title=settings.app_name,
        description="Агрегатор цен Steam · Plati · GGsel с аккаунтами, историей и избранным",
        version=settings.app_version,
        lifespan=lifespan,
    )

    origins = [o.strip() for o in settings.cors_origins.split(",") if o.strip()]
    application.add_middleware(
        CORSMiddleware,
        allow_origins=["*"] if origins == ["*"] else origins,
        allow_credentials=True,
        allow_methods=["*"],
        allow_headers=["*"],
    )
    application.add_middleware(SecurityHeadersMiddleware)

    application.include_router(auth.router)
    application.include_router(history.router)
    application.include_router(favorites.router)
    application.include_router(dashboard.router)
    application.include_router(prices.router)
    application.include_router(ads.router)
    application.include_router(tracking.router)

    @application.get("/api/health", response_model=HealthResponse)
    async def health() -> HealthResponse:
        db_ok = check_db()
        return HealthResponse(
            status="ok" if db_ok else "degraded",
            db="ok" if db_ok else "error",
            version=settings.app_version,
        )

    if STATIC_DIR.exists():
        application.mount("/static", StaticFiles(directory=str(STATIC_DIR)), name="static")

    @application.get("/")
    async def index() -> FileResponse:
        index_path = STATIC_DIR / "index.html"
        if not index_path.exists():
            raise HTTPException(status_code=404, detail="Frontend not found")
        return FileResponse(index_path)

    return application


app = create_app()
