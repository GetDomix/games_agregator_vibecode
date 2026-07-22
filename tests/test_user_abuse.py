"""Hostile power-user abuse tests: auth edges, IDOR, spam, XSS contracts, weird inputs."""

from __future__ import annotations

from pathlib import Path
from unittest.mock import AsyncMock, patch

from fastapi.testclient import TestClient

from app.schemas import (
    KindStats,
    MarketplaceStats,
    OfferLink,
    PriceResponse,
    ProductKind,
    SteamPrice,
)
from app.services import persistence as persistence_mod


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
            header_image="https://example.com/h.jpg",
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


# ── Registration / login edge cases ─────────────────────────────────────────


def test_register_rejects_password_over_bcrypt_limit(client: TestClient):
    """bcrypt only uses 72 bytes; longer passwords must not silently truncate."""
    resp = client.post(
        "/api/auth/register",
        json={"email": "longpw@test.com", "password": "a" * 73},
    )
    assert resp.status_code == 422


def test_register_accepts_password_exactly_72(client: TestClient):
    pw = "a" * 72
    reg = client.post(
        "/api/auth/register",
        json={"email": "pw72@test.com", "password": pw},
    )
    assert reg.status_code == 201, reg.text
    login = client.post("/api/auth/login", json={"email": "pw72@test.com", "password": pw})
    assert login.status_code == 200
    # Truncation confusion: shorter prefix must NOT authenticate
    bad = client.post("/api/auth/login", json={"email": "pw72@test.com", "password": "a" * 71})
    assert bad.status_code == 401


def test_register_double_submit_same_email(client: TestClient):
    payload = {"email": "dup-abuse@test.com", "password": "password1"}
    assert client.post("/api/auth/register", json=payload).status_code == 201
    second = client.post("/api/auth/register", json=payload)
    assert second.status_code == 400


def test_login_unknown_email_same_shape_as_bad_password(client: TestClient):
    _register(client, "known@test.com")
    unknown = client.post(
        "/api/auth/login",
        json={"email": "nosuch@test.com", "password": "password1"},
    )
    wrong = client.post(
        "/api/auth/login",
        json={"email": "known@test.com", "password": "wrong-pass"},
    )
    assert unknown.status_code == 401
    assert wrong.status_code == 401
    assert unknown.json()["detail"] == wrong.json()["detail"]


def test_profile_unicode_and_emoji_ok(client: TestClient, auth_headers: dict):
    resp = client.patch(
        "/api/auth/me",
        headers=auth_headers,
        json={"display_name": "🎮 Игрок — 测试"},
    )
    assert resp.status_code == 200
    assert "🎮" in resp.json()["display_name"]


def test_profile_xss_payload_stored_but_not_executed_contract(client: TestClient, auth_headers: dict):
    """API may store markup; frontend must escape (contract checked below)."""
    payload = '<script>alert("xss")</script>'
    resp = client.patch(
        "/api/auth/me",
        headers=auth_headers,
        json={"display_name": payload[:80]},
    )
    assert resp.status_code == 200
    assert "<script>" in resp.json()["display_name"]


# ── Cabinet APIs while logged out ───────────────────────────────────────────


def test_cabinet_apis_require_auth(client: TestClient):
    routes = [
        ("GET", "/api/auth/me"),
        ("PATCH", "/api/auth/me"),
        ("GET", "/api/me/dashboard"),
        ("GET", "/api/me/history"),
        ("DELETE", "/api/me/history"),
        ("DELETE", "/api/me/history/1"),
        ("GET", "/api/me/favorites"),
        ("POST", "/api/me/favorites"),
        ("PATCH", "/api/me/favorites/1"),
        ("DELETE", "/api/me/favorites/1"),
        ("POST", "/api/me/favorites/refresh"),
    ]
    for method, path in routes:
        if method == "GET":
            r = client.get(path)
        elif method == "POST":
            body = {"appid": 1, "game_name": "X"} if path.endswith("/favorites") else None
            r = client.post(path, json=body)
        elif method == "PATCH":
            body = (
                {"display_name": "x"}
                if path.endswith("/me")
                else {"notes": "n"}
            )
            r = client.patch(path, json=body)
        else:
            r = client.delete(path)
        assert r.status_code == 401, f"{method} {path} -> {r.status_code}"


# ── Favorites: validation, spoof, double-submit, concurrent-ish ─────────────


def test_favorite_rejects_non_positive_appid(client: TestClient, auth_headers: dict):
    for appid in (0, -1, -999):
        r = client.post(
            "/api/me/favorites",
            headers=auth_headers,
            json={"appid": appid, "game_name": "X"},
        )
        assert r.status_code == 422, appid


def test_favorite_rejects_whitespace_game_name(client: TestClient, auth_headers: dict):
    r = client.post(
        "/api/me/favorites",
        headers=auth_headers,
        json={"appid": 10, "game_name": "   "},
    )
    assert r.status_code == 422


def test_favorite_rejects_negative_prices(client: TestClient, auth_headers: dict):
    r = client.post(
        "/api/me/favorites",
        headers=auth_headers,
        json={
            "appid": 11,
            "game_name": "X",
            "target_price_rub": -1,
            "last_steam_price_rub": -5,
        },
    )
    assert r.status_code == 422


def test_favorite_rejects_non_http_header_image(client: TestClient, auth_headers: dict):
    for bad in ("javascript:alert(1)", "data:text/html,<h1>x</h1>", "ftp://evil/x", "x" * 600):
        r = client.post(
            "/api/me/favorites",
            headers=auth_headers,
            json={"appid": 12, "game_name": "X", "header_image": bad},
        )
        assert r.status_code == 422, bad[:40]


def test_favorite_allows_https_header(client: TestClient, auth_headers: dict):
    r = client.post(
        "/api/me/favorites",
        headers=auth_headers,
        json={
            "appid": 13,
            "game_name": "Safe",
            "header_image": "https://cdn.example.com/game.jpg",
        },
    )
    assert r.status_code == 201, r.text


def test_favorite_appid_spoof_without_steam_ownership_allowed_as_wishlist(
    client: TestClient, auth_headers: dict
):
    """Price tracker wishlist: any positive appid is allowed (not ownership-gated)."""
    r = client.post(
        "/api/me/favorites",
        headers=auth_headers,
        json={"appid": 999_999_999, "game_name": "Maybe Future Game", "last_steam_price_rub": 1},
    )
    assert r.status_code == 201
    assert r.json()["appid"] == 999_999_999


def test_favorite_double_submit_same_appid_is_upsert(client: TestClient, auth_headers: dict):
    a = client.post(
        "/api/me/favorites",
        headers=auth_headers,
        json={"appid": 555, "game_name": "First", "last_steam_price_rub": 10},
    )
    b = client.post(
        "/api/me/favorites",
        headers=auth_headers,
        json={"appid": 555, "game_name": "Second", "last_steam_price_rub": 9},
    )
    assert a.status_code == 201
    assert b.status_code == 201
    listed = client.get("/api/me/favorites", headers=auth_headers).json()
    assert listed["total"] == 1
    assert listed["items"][0]["game_name"] == "Second"


def test_favorite_xss_game_name_escaped_in_frontend_sources():
    """Stored game_name markup must be escaped in cabinet/app JS (not raw innerHTML)."""
    root = Path(__file__).resolve().parents[1] / "app" / "static"
    cabinet = (root / "cabinet.js").read_text(encoding="utf-8")
    app_js = (root / "app.js").read_text(encoding="utf-8")
    assert "function escapeHtml" in cabinet
    assert "escapeHtml(f.game_name)" in cabinet or "escapeHtml(f.game_name)" in cabinet.replace(" ", "")
    assert "escapeHtml" in cabinet and "game_name" in cabinet
    assert "escapeHtml(steam.name)" in app_js or "escapeHtml(steam.name)" in app_js.replace(" ", "")
    # Dangerous raw inject of game_name without escape would look like ${f.game_name} alone
    assert "${f.game_name}" not in cabinet
    assert "${h.game_name}" not in cabinet
    assert "${steam.name}" not in app_js


def test_favorite_idor_patch_delete_other_user(client: TestClient):
    a = _register(client, "fav-idor-a@test.com")
    b = _register(client, "fav-idor-b@test.com")
    ha, hb = _headers(a["access_token"]), _headers(b["access_token"])
    assert (
        client.post(
            "/api/me/favorites",
            headers=ha,
            json={"appid": 4242, "game_name": "Secret"},
        ).status_code
        == 201
    )
    assert client.patch("/api/me/favorites/4242", headers=hb, json={"notes": "pwn"}).status_code == 404
    assert client.delete("/api/me/favorites/4242", headers=hb).status_code == 404
    assert client.get("/api/me/favorites", headers=ha).json()["total"] == 1


# ── History IDOR + spam soft cap ────────────────────────────────────────────


def test_history_idor_guess_item_id(client: TestClient):
    a = _register(client, "hist-idor-a@test.com")
    b = _register(client, "hist-idor-b@test.com")
    ha, hb = _headers(a["access_token"]), _headers(b["access_token"])

    with patch("app.routers.prices.aggregate_prices", new=AsyncMock(return_value=_fake_price())):
        r = client.get("/api/prices", params={"q": "Hades"}, headers=ha)
    assert r.status_code == 200

    item_id = client.get("/api/me/history", headers=ha).json()["items"][0]["id"]
    # Guess sequential IDs
    for guess in (item_id, item_id + 1, 1, 999999):
        del_r = client.delete(f"/api/me/history/{guess}", headers=hb)
        assert del_r.status_code == 404, guess

    assert client.get("/api/me/history", headers=ha).json()["total"] >= 1
    assert client.delete(f"/api/me/history/{item_id}", headers=ha).status_code == 204


def test_history_spam_pruned_to_soft_cap(client: TestClient, auth_headers: dict, monkeypatch):
    monkeypatch.setattr(persistence_mod, "HISTORY_SOFT_CAP", 5)
    monkeypatch.setattr(persistence_mod, "SNAPSHOT_SOFT_CAP", 5)

    with patch("app.routers.prices.aggregate_prices", new=AsyncMock(return_value=_fake_price())):
        for i in range(12):
            r = client.get("/api/prices", params={"q": f"Game{i}"}, headers=auth_headers)
            assert r.status_code == 200, r.text

    hist = client.get("/api/me/history", headers=auth_headers, params={"limit": 200}).json()
    assert hist["total"] == 5
    assert len(hist["items"]) == 5


# ── Price / search weird queries ────────────────────────────────────────────


def test_prices_whitespace_only_rejected(client: TestClient):
    r = client.get("/api/prices", params={"q": "   "})
    assert r.status_code == 400
    assert "Пустой" in r.json()["detail"]


def test_search_whitespace_only_rejected(client: TestClient):
    r = client.get("/api/search", params={"q": "   "})
    assert r.status_code == 400


def test_prices_negative_and_zero_appid_rejected(client: TestClient):
    with patch("app.routers.prices.aggregate_prices", new=AsyncMock(return_value=_fake_price())):
        assert client.get("/api/prices", params={"q": "Hades", "appid": -5}).status_code == 422
        assert client.get("/api/prices", params={"q": "Hades", "appid": 0}).status_code == 422


def test_prices_unicode_emoji_query_ok(client: TestClient):
    with patch(
        "app.routers.prices.aggregate_prices",
        new=AsyncMock(return_value=_fake_price(query="🎮 Hades")),
    ):
        r = client.get("/api/prices", params={"q": "🎮 Hades"})
    assert r.status_code == 200
    assert r.json()["query"] == "🎮 Hades"


def test_prices_empty_string_rejected(client: TestClient):
    assert client.get("/api/prices", params={"q": ""}).status_code == 422


def test_prices_control_chars_and_long_ok_or_bounded(client: TestClient):
    with patch("app.routers.prices.aggregate_prices", new=AsyncMock(return_value=_fake_price())):
        r = client.get("/api/prices", params={"q": "Hades\x00null"})
        # either accepted as string or validation — must not 500
        assert r.status_code in (200, 400, 422)
    assert client.get("/api/prices", params={"q": "x" * 121}).status_code == 422


def test_search_too_long_rejected(client: TestClient):
    assert client.get("/api/search", params={"q": "z" * 121}).status_code == 422


# ── Auth token weirdness ────────────────────────────────────────────────────


def test_empty_bearer_and_malformed_auth(client: TestClient):
    assert client.get("/api/auth/me", headers={"Authorization": "Bearer "}).status_code == 401
    assert client.get("/api/auth/me", headers={"Authorization": "Bearer"}).status_code == 401
    assert client.get("/api/me/dashboard", headers={"Authorization": "Token abc"}).status_code == 401


def test_dashboard_does_not_leak_other_user_email(client: TestClient):
    a = _register(client, "dash-leak-a@test.com", name="Alice")
    b = _register(client, "dash-leak-b@test.com", name="Bob")
    da = client.get("/api/me/dashboard", headers=_headers(a["access_token"])).json()
    db = client.get("/api/me/dashboard", headers=_headers(b["access_token"])).json()
    assert da["user"]["email"] == "dash-leak-a@test.com"
    assert db["user"]["email"] == "dash-leak-b@test.com"
    assert "dash-leak-b" not in str(da)
    assert "dash-leak-a" not in str(db)
