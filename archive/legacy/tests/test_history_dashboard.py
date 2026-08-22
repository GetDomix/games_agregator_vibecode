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


def _fake_price(query: str = "Hades") -> PriceResponse:
    return PriceResponse(
        query=query,
        steam=SteamPrice(
            appid=1145360,
            name="Hades",
            store_url="https://store.steampowered.com/app/1145360/",
            price_rub=264.0,
            header_image="https://example.com/h.jpg",
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
                    min_price=200,
                    avg_price=200,
                    popular=OfferLink(title="k", url="https://x", price_rub=200, kind=ProductKind.KEY),
                    cheapest=OfferLink(title="k", url="https://x", price_rub=200, kind=ProductKind.KEY),
                )
            ],
        ),
        ggsel=MarketplaceStats(marketplace="ggsel", label="GGsel", scanned_offers=0),
        warnings=[],
    )


def test_prices_saves_history_when_authed(client: TestClient, auth_headers: dict):
    with patch("app.routers.prices.aggregate_prices", new=AsyncMock(return_value=_fake_price())):
        resp = client.get("/api/prices", params={"q": "Hades"}, headers=auth_headers)
    assert resp.status_code == 200
    assert resp.json()["saved_to_history"] is True

    hist = client.get("/api/me/history", headers=auth_headers)
    assert hist.status_code == 200
    assert hist.json()["total"] >= 1
    assert hist.json()["items"][0]["game_name"] == "Hades"


def test_prices_guest_no_history_flag(client: TestClient):
    with patch("app.routers.prices.aggregate_prices", new=AsyncMock(return_value=_fake_price())):
        resp = client.get("/api/prices", params={"q": "Hades"})
    assert resp.status_code == 200
    assert resp.json()["saved_to_history"] is False


def test_clear_history(client: TestClient, auth_headers: dict):
    with patch("app.routers.prices.aggregate_prices", new=AsyncMock(return_value=_fake_price())):
        client.get("/api/prices", params={"q": "Hades"}, headers=auth_headers)
    assert client.delete("/api/me/history", headers=auth_headers).status_code == 204
    assert client.get("/api/me/history", headers=auth_headers).json()["total"] == 0


def test_dashboard_and_trends(client: TestClient, auth_headers: dict):
    with patch("app.routers.prices.aggregate_prices", new=AsyncMock(return_value=_fake_price())):
        client.get("/api/prices", params={"q": "Hades"}, headers=auth_headers)

    dash = client.get("/api/me/dashboard", headers=auth_headers)
    assert dash.status_code == 200
    body = dash.json()
    assert body["searches_total"] >= 1
    assert body["user"]["email"] == "player@example.com"
    assert isinstance(body["ctas"], list)

    trends = client.get("/api/trends/popular")
    assert trends.status_code == 200
    assert trends.json()["items"]


def test_delete_missing_history_item_404(client: TestClient, auth_headers: dict):
    assert client.delete("/api/me/history/999999", headers=auth_headers).status_code == 404


def test_history_limit_bounds(client: TestClient, auth_headers: dict):
    assert client.get("/api/me/history", headers=auth_headers, params={"limit": 0}).status_code == 422
    assert client.get("/api/me/history", headers=auth_headers, params={"limit": 201}).status_code == 422
    ok = client.get("/api/me/history", headers=auth_headers, params={"limit": 1})
    assert ok.status_code == 200


def test_trends_seed_when_no_community_history(client: TestClient):
    resp = client.get("/api/trends/popular", params={"limit": 3})
    assert resp.status_code == 200
    body = resp.json()
    assert body["source"] == "seed"
    assert len(body["items"]) == 3
