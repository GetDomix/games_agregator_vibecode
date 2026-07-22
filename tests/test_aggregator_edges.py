"""Edge cases for aggregate_prices: no steam match, appid override, market errors."""

from __future__ import annotations

import httpx
import pytest

from app.config import Settings
from app.services.aggregator import aggregate_prices


def _settings() -> Settings:
    return Settings(
        digiseller_partner_id="",
        http_timeout_seconds=5.0,
        plati_max_pages=1,
        plati_page_size=10,
        ggsel_limit=10,
        steam_cc="ru",
        steam_lang="russian",
        currency="RUB",
    )


def _empty_markets(request: httpx.Request) -> httpx.Response:
    url = str(request.url)
    if "storesearch" in url:
        return httpx.Response(200, json={"items": []})
    if "search.ashx" in url:
        return httpx.Response(
            200,
            json={"Pagenum": 1, "Pagesize": 10, "Totalpages": 1, "items": []},
        )
    if "elastic/goods/query" in url:
        return httpx.Response(200, json={"data": {"items": [], "total": 0}})
    return httpx.Response(404, text=url)


@pytest.mark.asyncio
async def test_aggregate_no_steam_match_still_searches_markets():
    transport = httpx.MockTransport(_empty_markets)
    async with httpx.AsyncClient(transport=transport) as client:
        result = await aggregate_prices(client, "ObscureUnknownGameXYZ", _settings())

    assert result.steam is None
    assert result.candidates == []
    assert any("Steam" in w for w in result.warnings)
    assert result.plati.error is None
    assert result.ggsel.error is None


@pytest.mark.asyncio
async def test_aggregate_with_explicit_appid():
    def router(request: httpx.Request) -> httpx.Response:
        url = str(request.url)
        if "storesearch" in url:
            return httpx.Response(
                200,
                json={
                    "items": [
                        {
                            "type": "app",
                            "name": "Hades",
                            "id": 1145360,
                            "price": {"currency": "RUB", "initial": 88000, "final": 26400},
                        },
                        {
                            "type": "app",
                            "name": "Hades II",
                            "id": 1145350,
                            "price": {"currency": "RUB", "initial": 100000, "final": 100000},
                        },
                    ]
                },
            )
        if "appdetails" in url:
            assert "1145350" in url or request.url.params.get("appids") == "1145350"
            return httpx.Response(
                200,
                json={
                    "1145350": {
                        "success": True,
                        "data": {
                            "name": "Hades II",
                            "is_free": False,
                            "header_image": "https://img/h2.jpg",
                            "price_overview": {
                                "currency": "RUB",
                                "initial": 100000,
                                "final": 90000,
                                "discount_percent": 10,
                            },
                        },
                    }
                },
            )
        if "search.ashx" in url:
            return httpx.Response(
                200,
                json={"Pagenum": 1, "Pagesize": 10, "Totalpages": 1, "items": []},
            )
        if "elastic/goods/query" in url:
            return httpx.Response(200, json={"data": {"items": [], "total": 0}})
        return httpx.Response(404, text=url)

    transport = httpx.MockTransport(router)
    async with httpx.AsyncClient(transport=transport) as client:
        result = await aggregate_prices(client, "Hades", _settings(), appid=1145350)

    assert result.steam is not None
    assert result.steam.appid == 1145350
    assert result.steam.name == "Hades II"
    assert result.steam.price_rub == 900.0


@pytest.mark.asyncio
async def test_aggregate_market_errors_become_warnings():
    def router(request: httpx.Request) -> httpx.Response:
        url = str(request.url)
        if "storesearch" in url:
            return httpx.Response(
                200,
                json={
                    "items": [
                        {
                            "type": "app",
                            "name": "Hades",
                            "id": 1145360,
                            "price": {"currency": "RUB", "initial": 10000, "final": 10000},
                        }
                    ]
                },
            )
        if "appdetails" in url:
            return httpx.Response(
                200,
                json={
                    "1145360": {
                        "success": True,
                        "data": {
                            "name": "Hades",
                            "is_free": False,
                            "price_overview": {
                                "currency": "RUB",
                                "initial": 10000,
                                "final": 10000,
                                "discount_percent": 0,
                            },
                        },
                    }
                },
            )
        if "search.ashx" in url:
            return httpx.Response(503, text="plati down")
        if "elastic/goods/query" in url:
            return httpx.Response(403, json={"error": "nope"})
        return httpx.Response(404, text=url)

    transport = httpx.MockTransport(router)
    async with httpx.AsyncClient(transport=transport) as client:
        result = await aggregate_prices(client, "Hades", _settings())

    assert result.steam is not None
    assert result.plati.error is not None
    assert result.ggsel.error is not None
    assert any(w.startswith("Plati:") for w in result.warnings)
    assert any(w.startswith("GGsel:") for w in result.warnings)
    assert result.plati.by_kind == []
    assert result.ggsel.by_kind == []


@pytest.mark.asyncio
async def test_aggregate_delisted_steam_adds_warning():
    def router(request: httpx.Request) -> httpx.Response:
        url = str(request.url)
        if "storesearch" in url:
            # RU empty → US fallback in search_steam
            if "cc=RU" in url or "cc=ru" in url:
                return httpx.Response(200, json={"items": []})
            return httpx.Response(
                200,
                json={
                    "items": [
                        {
                            "type": "app",
                            "name": "Banned Game",
                            "id": 999,
                            "price": {"currency": "USD", "initial": 1999, "final": 1999},
                        }
                    ]
                },
            )
        if "appdetails" in url:
            if "cc=ru" in url:
                return httpx.Response(200, json={"999": {"success": False}})
            return httpx.Response(
                200,
                json={
                    "999": {
                        "success": True,
                        "data": {
                            "name": "Banned Game",
                            "is_free": False,
                            "price_overview": {
                                "currency": "USD",
                                "initial": 1999,
                                "final": 1999,
                                "discount_percent": 0,
                            },
                        },
                    }
                },
            )
        if "search.ashx" in url:
            return httpx.Response(
                200,
                json={"Pagenum": 1, "Pagesize": 10, "Totalpages": 1, "items": []},
            )
        if "elastic/goods/query" in url:
            return httpx.Response(200, json={"data": {"items": [], "total": 0}})
        return httpx.Response(404, text=url)

    transport = httpx.MockTransport(router)
    async with httpx.AsyncClient(transport=transport) as client:
        result = await aggregate_prices(client, "Banned Game", _settings())

    assert result.steam is not None
    assert result.steam.available_in_ru is False
    assert result.steam.price_rub is None
    assert any("недоступна" in w.lower() or "Steam" in w for w in result.warnings)
