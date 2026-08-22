from __future__ import annotations

import os
from collections.abc import Generator

import pytest
from fastapi.testclient import TestClient

os.environ["SECRET_KEY"] = "test-secret-key-not-for-prod"
os.environ["APP_ENV"] = "development"
os.environ["DATABASE_URL"] = "sqlite://"
os.environ["ADS_ENABLED"] = "true"
os.environ["RATE_LIMIT_LOGIN_PER_MINUTE"] = "1000"
os.environ["RATE_LIMIT_PRICES_PER_MINUTE"] = "1000"
os.environ["FREE_SEARCHES_PER_DAY"] = "10000"
os.environ["GUEST_SEARCHES_PER_DAY"] = "10000"
os.environ["RATE_LIMIT_WATCHLIST_REFRESH_PER_HOUR"] = "1000"
os.environ["FREE_SEARCHES_PER_DAY"] = "1000"
os.environ["GUEST_SEARCHES_PER_DAY"] = "1000"
os.environ["RATE_LIMIT_WATCHLIST_REFRESH_PER_HOUR"] = "100"

from app.config import get_settings

get_settings.cache_clear()

from app.db import Base, engine, init_db
from app.main import app


@pytest.fixture(autouse=True)
def _reset_db():
    get_settings.cache_clear()
    from app import db_models  # noqa: F401

    Base.metadata.drop_all(bind=engine)
    Base.metadata.create_all(bind=engine)
    yield
    Base.metadata.drop_all(bind=engine)


@pytest.fixture()
def client() -> Generator[TestClient, None, None]:
    with TestClient(app) as c:
        yield c


@pytest.fixture()
def auth_user(client: TestClient) -> dict:
    resp = client.post(
        "/api/auth/register",
        json={"email": "player@example.com", "password": "secret123", "display_name": "Player"},
    )
    assert resp.status_code == 201, resp.text
    return resp.json()


@pytest.fixture()
def auth_headers(auth_user: dict) -> dict[str, str]:
    return {"Authorization": f"Bearer {auth_user['access_token']}"}
