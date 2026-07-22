"""Integration tests for API rate limiting (login/register/prices)."""

from __future__ import annotations

from unittest.mock import AsyncMock, patch

from fastapi.testclient import TestClient

from app.config import get_settings
from app.schemas import MarketplaceStats, PriceResponse
from app.services.rate_limit import limiter


def _clear_limiter():
    """Reset in-memory rate limiter state between tests."""
    with limiter._lock:
        limiter._hits.clear()


def test_login_rate_limit_returns_429(client: TestClient, monkeypatch):
    _clear_limiter()
    monkeypatch.setenv("RATE_LIMIT_LOGIN_PER_MINUTE", "3")
    get_settings.cache_clear()

    client.post(
        "/api/auth/register",
        json={"email": "rl@test.com", "password": "password1"},
    )
    # Failed logins also count
    codes = []
    for _ in range(5):
        r = client.post(
            "/api/auth/login",
            json={"email": "rl@test.com", "password": "wrong-password"},
        )
        codes.append(r.status_code)

    get_settings.cache_clear()
    _clear_limiter()

    assert 429 in codes
    assert codes.count(401) + codes.count(429) == 5
    # At least one success path before throttle
    assert 401 in codes


def test_register_rate_limit_returns_429(client: TestClient, monkeypatch):
    _clear_limiter()
    monkeypatch.setenv("RATE_LIMIT_LOGIN_PER_MINUTE", "2")
    get_settings.cache_clear()

    codes = []
    for i in range(4):
        r = client.post(
            "/api/auth/register",
            json={"email": f"rl{i}@test.com", "password": "password1"},
        )
        codes.append(r.status_code)

    get_settings.cache_clear()
    _clear_limiter()

    assert 201 in codes
    assert 429 in codes


def test_prices_rate_limit_returns_429(client: TestClient, monkeypatch):
    _clear_limiter()
    monkeypatch.setenv("RATE_LIMIT_PRICES_PER_MINUTE", "2")
    get_settings.cache_clear()

    fake = PriceResponse(
        query="Hades",
        steam=None,
        candidates=[],
        plati=MarketplaceStats(marketplace="plati", label="Plati"),
        ggsel=MarketplaceStats(marketplace="ggsel", label="GGsel"),
    )
    codes = []
    with patch("app.routers.prices.aggregate_prices", new=AsyncMock(return_value=fake)):
        for _ in range(4):
            codes.append(client.get("/api/prices", params={"q": "Hades"}).status_code)

    get_settings.cache_clear()
    _clear_limiter()

    assert 200 in codes
    assert 429 in codes


def test_xff_creates_separate_rate_buckets(client: TestClient, monkeypatch):
    """Documented behavior: X-Forwarded-For is trusted for rate-limit keying."""
    _clear_limiter()
    monkeypatch.setenv("RATE_LIMIT_PRICES_PER_MINUTE", "1")
    get_settings.cache_clear()

    fake = PriceResponse(
        query="Hades",
        steam=None,
        candidates=[],
        plati=MarketplaceStats(marketplace="plati", label="Plati"),
        ggsel=MarketplaceStats(marketplace="ggsel", label="GGsel"),
    )
    with patch("app.routers.prices.aggregate_prices", new=AsyncMock(return_value=fake)):
        r1 = client.get(
            "/api/prices",
            params={"q": "Hades"},
            headers={"X-Forwarded-For": "1.1.1.1"},
        )
        r2 = client.get(
            "/api/prices",
            params={"q": "Hades"},
            headers={"X-Forwarded-For": "2.2.2.2"},
        )
        r3 = client.get(
            "/api/prices",
            params={"q": "Hades"},
            headers={"X-Forwarded-For": "1.1.1.1"},
        )

    get_settings.cache_clear()
    _clear_limiter()

    assert r1.status_code == 200
    assert r2.status_code == 200  # different IP bucket
    assert r3.status_code == 429  # same bucket exhausted
