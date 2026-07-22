from __future__ import annotations

from unittest.mock import AsyncMock, patch

from fastapi.testclient import TestClient

from app.schemas import (
    KindStats,
    MarketplaceStats,
    OfferLink,
    PriceResponse,
    ProductKind,
    SteamCandidate,
    SteamPrice,
)


def test_health(client: TestClient):
    resp = client.get("/api/health")
    assert resp.status_code == 200
    body = resp.json()
    assert body["status"] in ("ok", "degraded")
    assert "db" in body


def test_index_served(client: TestClient):
    resp = client.get("/")
    assert resp.status_code == 200
    assert "text/html" in resp.headers["content-type"]
    assert "Game Price Aggregator" in resp.text
    assert "auth.js" in resp.text


def test_search_endpoint(client: TestClient):
    fake = [
        SteamCandidate(
            appid=1145360,
            name="Hades",
            price_rub=264.0,
            price_initial_rub=880.0,
            discount_percent=70,
        )
    ]
    with patch("app.routers.prices.search_candidates", new=AsyncMock(return_value=fake)):
        resp = client.get("/api/search", params={"q": "Hades"})
    assert resp.status_code == 200
    body = resp.json()
    assert body["query"] == "Hades"
    assert body["candidates"][0]["appid"] == 1145360


def test_prices_endpoint(client: TestClient):
    fake = PriceResponse(
        query="Hades",
        steam=SteamPrice(
            appid=1145360,
            name="Hades",
            store_url="https://store.steampowered.com/app/1145360/",
            price_rub=264.0,
            price_initial_rub=880.0,
            discount_percent=70,
            available_in_ru=True,
        ),
        candidates=[],
        plati=MarketplaceStats(
            marketplace="plati",
            label="Plati.Market",
            total_offers=1,
            scanned_offers=1,
            by_kind=[
                KindStats(
                    kind=ProductKind.KEY,
                    label="Ключ",
                    count=1,
                    min_price=200.0,
                    avg_price=200.0,
                    popular=OfferLink(
                        title="key",
                        url="https://plati.market/itm/1",
                        price_rub=200.0,
                        sales=10,
                        kind=ProductKind.KEY,
                    ),
                    cheapest=OfferLink(
                        title="key",
                        url="https://plati.market/itm/1",
                        price_rub=200.0,
                        sales=10,
                        kind=ProductKind.KEY,
                    ),
                )
            ],
        ),
        ggsel=MarketplaceStats(
            marketplace="ggsel",
            label="GGsel",
            total_offers=0,
            scanned_offers=0,
            by_kind=[],
        ),
        warnings=[],
    )
    with patch("app.routers.prices.aggregate_prices", new=AsyncMock(return_value=fake)):
        resp = client.get("/api/prices", params={"q": "Hades", "appid": 1145360})
    assert resp.status_code == 200
    body = resp.json()
    assert body["steam"]["price_rub"] == 264.0
    assert body["plati"]["by_kind"][0]["min_price"] == 200.0


def test_prices_empty_query_rejected(client: TestClient):
    resp = client.get("/api/prices", params={"q": ""})
    assert resp.status_code == 422
