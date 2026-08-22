"""Security-oriented API tests: tokens, authz, isolation, headers."""

from __future__ import annotations

from datetime import datetime, timedelta, timezone
from unittest.mock import AsyncMock, patch

import jwt
from fastapi.testclient import TestClient

from app.config import get_settings
from app.schemas import (
    KindStats,
    MarketplaceStats,
    OfferLink,
    PriceResponse,
    ProductKind,
    SteamPrice,
)


def _register(client: TestClient, email: str, password: str = "password1", name: str = "U") -> dict:
    resp = client.post(
        "/api/auth/register",
        json={"email": email, "password": password, "display_name": name},
    )
    assert resp.status_code == 201, resp.text
    return resp.json()


def _headers(token: str) -> dict[str, str]:
    return {"Authorization": f"Bearer {token}"}


def _fake_price(query: str = "Hades", appid: int = 1145360) -> PriceResponse:
    return PriceResponse(
        query=query,
        steam=SteamPrice(
            appid=appid,
            name=query,
            store_url=f"https://store.steampowered.com/app/{appid}/",
            price_rub=100.0,
            available_in_ru=True,
        ),
        candidates=[],
        plati=MarketplaceStats(
            marketplace="plati",
            label="Plati",
            scanned_offers=1,
            by_kind=[
                KindStats(
                    kind=ProductKind.KEY,
                    label="Ключ",
                    count=1,
                    min_price=50,
                    avg_price=50,
                    popular=OfferLink(title="k", url="https://x", price_rub=50, kind=ProductKind.KEY),
                    cheapest=OfferLink(title="k", url="https://x", price_rub=50, kind=ProductKind.KEY),
                )
            ],
        ),
        ggsel=MarketplaceStats(marketplace="ggsel", label="GGsel", scanned_offers=0),
        warnings=[],
    )


# ── Token / auth edge cases ─────────────────────────────────────────────────


def test_invalid_token_rejected(client: TestClient):
    assert client.get("/api/auth/me", headers=_headers("not-a-jwt")).status_code == 401
    assert client.get("/api/auth/me", headers=_headers("a.b.c")).status_code == 401


def test_token_signed_with_wrong_secret_rejected(client: TestClient):
    _register(client, "victim@test.com")
    forged = jwt.encode(
        {
            "sub": "1",
            "exp": datetime.now(timezone.utc) + timedelta(hours=1),
            "iat": datetime.now(timezone.utc),
        },
        "attacker-secret-key",
        algorithm="HS256",
    )
    assert client.get("/api/auth/me", headers=_headers(forged)).status_code == 401


def test_expired_token_rejected(client: TestClient):
    _register(client, "exp@test.com")
    settings = get_settings()
    expired = jwt.encode(
        {
            "sub": "1",
            "exp": datetime.now(timezone.utc) - timedelta(seconds=10),
            "iat": datetime.now(timezone.utc) - timedelta(hours=1),
        },
        settings.secret_key,
        algorithm="HS256",
    )
    assert client.get("/api/auth/me", headers=_headers(expired)).status_code == 401


def test_token_missing_sub_rejected(client: TestClient):
    settings = get_settings()
    bad = jwt.encode(
        {
            "exp": datetime.now(timezone.utc) + timedelta(hours=1),
            "iat": datetime.now(timezone.utc),
        },
        settings.secret_key,
        algorithm="HS256",
    )
    assert client.get("/api/auth/me", headers=_headers(bad)).status_code == 401


def test_token_for_deleted_user_rejected(client: TestClient):
    """JWT for non-existent user id must not grant access."""
    settings = get_settings()
    orphan = jwt.encode(
        {
            "sub": "999999",
            "exp": datetime.now(timezone.utc) + timedelta(hours=1),
            "iat": datetime.now(timezone.utc),
        },
        settings.secret_key,
        algorithm="HS256",
    )
    assert client.get("/api/auth/me", headers=_headers(orphan)).status_code == 401


def test_alg_none_token_rejected(client: TestClient):
    """Classic JWT alg=none confusion attempt."""
    # PyJWT may refuse to encode alg=none; craft a minimal unsigned-looking payload
    import base64
    import json

    header = base64.urlsafe_b64encode(json.dumps({"alg": "none", "typ": "JWT"}).encode()).rstrip(b"=")
    payload = base64.urlsafe_b64encode(
        json.dumps(
            {
                "sub": "1",
                "exp": int((datetime.now(timezone.utc) + timedelta(hours=1)).timestamp()),
            }
        ).encode()
    ).rstrip(b"=")
    token = f"{header.decode()}.{payload.decode()}."
    assert client.get("/api/auth/me", headers=_headers(token)).status_code == 401


def test_bearer_scheme_required_not_raw_token(client: TestClient, auth_user: dict):
    token = auth_user["access_token"]
    # Missing Bearer scheme / wrong header form
    resp = client.get("/api/auth/me", headers={"Authorization": token})
    assert resp.status_code == 401


def test_auth_required_on_protected_routes(client: TestClient):
    protected = [
        ("GET", "/api/auth/me"),
        ("PATCH", "/api/auth/me"),
        ("GET", "/api/me/history"),
        ("DELETE", "/api/me/history"),
        ("GET", "/api/me/favorites"),
        ("POST", "/api/me/favorites"),
        ("GET", "/api/me/dashboard"),
    ]
    for method, path in protected:
        if method == "GET":
            resp = client.get(path)
        elif method == "POST":
            resp = client.post(path, json={"appid": 1, "game_name": "x"})
        elif method == "PATCH":
            resp = client.patch(path, json={"display_name": "x"})
        else:
            resp = client.delete(path)
        assert resp.status_code == 401, f"{method} {path} -> {resp.status_code}"


# ── Registration / password hardening ───────────────────────────────────────


def test_weak_password_too_short(client: TestClient):
    resp = client.post(
        "/api/auth/register",
        json={"email": "w@test.com", "password": "1234567"},
    )
    assert resp.status_code == 422


def test_whitespace_only_password_rejected(client: TestClient):
    resp = client.post(
        "/api/auth/register",
        json={"email": "ws@test.com", "password": "        "},
    )
    assert resp.status_code == 422


def test_invalid_email_rejected(client: TestClient):
    resp = client.post(
        "/api/auth/register",
        json={"email": "not-an-email", "password": "password1"},
    )
    assert resp.status_code == 422


def test_email_case_insensitive_duplicate(client: TestClient):
    assert (
        client.post(
            "/api/auth/register",
            json={"email": "Case@Test.COM", "password": "password1"},
        ).status_code
        == 201
    )
    dup = client.post(
        "/api/auth/register",
        json={"email": "case@test.com", "password": "password1"},
    )
    assert dup.status_code == 400


def test_login_email_case_insensitive(client: TestClient):
    _register(client, "Mixed@Example.com", password="password1")
    ok = client.post(
        "/api/auth/login",
        json={"email": "mixed@example.com", "password": "password1"},
    )
    assert ok.status_code == 200
    assert ok.json()["access_token"]


def test_blank_display_name_update_rejected(client: TestClient, auth_headers: dict):
    resp = client.patch(
        "/api/auth/me",
        headers=auth_headers,
        json={"display_name": "   "},
    )
    assert resp.status_code == 422


def test_password_hash_not_leaked_in_responses(client: TestClient):
    body = _register(client, "noleak@test.com")
    assert "password" not in body["user"]
    assert "password_hash" not in body["user"]
    me = client.get("/api/auth/me", headers=_headers(body["access_token"]))
    assert "password_hash" not in me.json()
    assert "password" not in me.json()


# ── Favorite isolation ──────────────────────────────────────────────────────


def test_favorites_isolated_between_users(client: TestClient):
    a = _register(client, "fav-a@test.com", name="A")
    b = _register(client, "fav-b@test.com", name="B")
    ha, hb = _headers(a["access_token"]), _headers(b["access_token"])

    add = client.post(
        "/api/me/favorites",
        headers=ha,
        json={"appid": 1145360, "game_name": "Hades", "last_steam_price_rub": 200},
    )
    assert add.status_code == 201

    # B cannot see A's favorite
    listed_b = client.get("/api/me/favorites", headers=hb)
    assert listed_b.status_code == 200
    assert listed_b.json()["total"] == 0
    assert listed_b.json()["items"] == []

    # B cannot delete A's favorite
    assert client.delete("/api/me/favorites/1145360", headers=hb).status_code == 404
    # A still has it
    assert client.get("/api/me/favorites", headers=ha).json()["total"] == 1

    # B cannot patch A's favorite
    assert (
        client.patch(
            "/api/me/favorites/1145360",
            headers=hb,
            json={"notes": "hacked"},
        ).status_code
        == 404
    )


def test_favorite_upsert_same_appid(client: TestClient, auth_headers: dict):
    first = client.post(
        "/api/me/favorites",
        headers=auth_headers,
        json={"appid": 10, "game_name": "Game", "last_steam_price_rub": 100},
    )
    assert first.status_code == 201
    second = client.post(
        "/api/me/favorites",
        headers=auth_headers,
        json={
            "appid": 10,
            "game_name": "Game Renamed",
            "last_steam_price_rub": 80,
            "target_price_rub": 50,
        },
    )
    assert second.status_code == 201
    assert second.json()["game_name"] == "Game Renamed"
    assert second.json()["target_price_rub"] == 50
    assert client.get("/api/me/favorites", headers=auth_headers).json()["total"] == 1


def test_favorite_negative_target_rejected(client: TestClient, auth_headers: dict):
    resp = client.post(
        "/api/me/favorites",
        headers=auth_headers,
        json={"appid": 1, "game_name": "X", "target_price_rub": -10},
    )
    assert resp.status_code == 422


# ── History isolation ───────────────────────────────────────────────────────


def test_history_isolated_between_users(client: TestClient):
    a = _register(client, "hist-a@test.com")
    b = _register(client, "hist-b@test.com")
    ha, hb = _headers(a["access_token"]), _headers(b["access_token"])

    with patch("app.routers.prices.aggregate_prices", new=AsyncMock(return_value=_fake_price())):
        r = client.get("/api/prices", params={"q": "Hades"}, headers=ha)
    assert r.status_code == 200
    assert r.json()["saved_to_history"] is True

    hist_a = client.get("/api/me/history", headers=ha).json()
    hist_b = client.get("/api/me/history", headers=hb).json()
    assert hist_a["total"] >= 1
    assert hist_b["total"] == 0

    item_id = hist_a["items"][0]["id"]
    # B cannot delete A's history row
    assert client.delete(f"/api/me/history/{item_id}", headers=hb).status_code == 404
    # A still has it
    assert client.get("/api/me/history", headers=ha).json()["total"] >= 1
    # A can delete own
    assert client.delete(f"/api/me/history/{item_id}", headers=ha).status_code == 204
    assert client.get("/api/me/history", headers=ha).json()["total"] == 0


def test_clear_history_does_not_affect_other_user(client: TestClient):
    a = _register(client, "clr-a@test.com")
    b = _register(client, "clr-b@test.com")
    ha, hb = _headers(a["access_token"]), _headers(b["access_token"])

    with patch("app.routers.prices.aggregate_prices", new=AsyncMock(return_value=_fake_price())):
        client.get("/api/prices", params={"q": "A"}, headers=ha)
        client.get("/api/prices", params={"q": "B"}, headers=hb)

    assert client.delete("/api/me/history", headers=ha).status_code == 204
    assert client.get("/api/me/history", headers=ha).json()["total"] == 0
    assert client.get("/api/me/history", headers=hb).json()["total"] >= 1


def test_dashboard_isolated(client: TestClient):
    a = _register(client, "dash-a@test.com", name="DashA")
    b = _register(client, "dash-b@test.com", name="DashB")
    ha, hb = _headers(a["access_token"]), _headers(b["access_token"])

    with patch("app.routers.prices.aggregate_prices", new=AsyncMock(return_value=_fake_price())):
        client.get("/api/prices", params={"q": "Hades"}, headers=ha)

    da = client.get("/api/me/dashboard", headers=ha).json()
    db = client.get("/api/me/dashboard", headers=hb).json()
    assert da["user"]["email"] == "dash-a@test.com"
    assert db["user"]["email"] == "dash-b@test.com"
    assert da["searches_total"] >= 1
    assert db["searches_total"] == 0


# ── Optional auth on /api/prices ────────────────────────────────────────────


def test_prices_invalid_token_treated_as_guest(client: TestClient):
    """Optional auth: bad Bearer must not 401; acts as guest (no history save)."""
    with patch("app.routers.prices.aggregate_prices", new=AsyncMock(return_value=_fake_price())):
        resp = client.get(
            "/api/prices",
            params={"q": "Hades"},
            headers=_headers("totally-invalid"),
        )
    assert resp.status_code == 200
    assert resp.json()["saved_to_history"] is False
    assert resp.json()["is_favorite"] is False


def test_prices_authed_sets_is_favorite_flag(client: TestClient, auth_headers: dict):
    client.post(
        "/api/me/favorites",
        headers=auth_headers,
        json={"appid": 1145360, "game_name": "Hades"},
    )
    with patch("app.routers.prices.aggregate_prices", new=AsyncMock(return_value=_fake_price())):
        resp = client.get("/api/prices", params={"q": "Hades"}, headers=auth_headers)
    assert resp.status_code == 200
    body = resp.json()
    assert body["saved_to_history"] is True
    assert body["is_favorite"] is True


# ── Security headers / public surface ───────────────────────────────────────


def test_security_headers_present(client: TestClient):
    resp = client.get("/api/health")
    assert resp.headers.get("x-content-type-options") == "nosniff"
    assert resp.headers.get("x-frame-options") == "DENY"
    assert resp.headers.get("referrer-policy") == "strict-origin-when-cross-origin"
    assert "geolocation=()" in (resp.headers.get("permissions-policy") or "")


def test_trends_public_no_auth(client: TestClient):
    resp = client.get("/api/trends/popular")
    assert resp.status_code == 200
    body = resp.json()
    assert body["source"] in ("seed", "community")
    assert len(body["items"]) >= 1


def test_search_query_too_long_rejected(client: TestClient):
    resp = client.get("/api/search", params={"q": "x" * 121})
    assert resp.status_code == 422


def test_prices_query_too_long_rejected(client: TestClient):
    resp = client.get("/api/prices", params={"q": "y" * 121})
    assert resp.status_code == 422
