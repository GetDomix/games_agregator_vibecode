from __future__ import annotations

import httpx
import pytest

from app.config import Settings
from app.services.steam import get_steam_details, pick_best_candidate, search_steam
from app.models import SteamCandidate


def _settings(**kwargs) -> Settings:
    base = dict(
        digiseller_partner_id="",
        http_timeout_seconds=5.0,
        plati_max_pages=1,
        plati_page_size=20,
        ggsel_limit=20,
        steam_cc="ru",
        steam_lang="russian",
        currency="RUB",
    )
    base.update(kwargs)
    return Settings(**base)


@pytest.mark.asyncio
async def test_search_steam_parses_prices():
    def handler(request: httpx.Request) -> httpx.Response:
        assert "storesearch" in str(request.url)
        return httpx.Response(
            200,
            json={
                "total": 1,
                "items": [
                    {
                        "type": "app",
                        "name": "Hades",
                        "id": 1145360,
                        "tiny_image": "https://example.com/h.jpg",
                        "price": {"currency": "RUB", "initial": 88000, "final": 26400},
                    }
                ],
            },
        )

    transport = httpx.MockTransport(handler)
    async with httpx.AsyncClient(transport=transport) as client:
        result = await search_steam(client, "Hades", _settings())

    assert len(result) == 1
    assert result[0].appid == 1145360
    assert result[0].price_rub == 264.0
    assert result[0].price_initial_rub == 880.0
    assert result[0].discount_percent == 70
    assert result[0].available_in_ru is True


@pytest.mark.asyncio
async def test_search_steam_falls_back_to_us():
    calls: list[str] = []

    def handler(request: httpx.Request) -> httpx.Response:
        calls.append(str(request.url))
        if "cc=RU" in str(request.url) or "cc=ru" in str(request.url):
            return httpx.Response(200, json={"total": 0, "items": []})
        return httpx.Response(
            200,
            json={
                "total": 1,
                "items": [
                    {
                        "type": "app",
                        "name": "Cyberpunk 2077",
                        "id": 1091500,
                        "tiny_image": "https://example.com/cp.jpg",
                        "price": {"currency": "USD", "initial": 5999, "final": 5999},
                    }
                ],
            },
        )

    transport = httpx.MockTransport(handler)
    async with httpx.AsyncClient(transport=transport) as client:
        result = await search_steam(client, "Cyberpunk 2077", _settings())

    assert len(calls) == 2
    assert result[0].appid == 1091500
    assert result[0].available_in_ru is False
    # USD must not be shown as RUB
    assert result[0].price_rub is None


@pytest.mark.asyncio
async def test_get_steam_details_ru_price():
    def handler(request: httpx.Request) -> httpx.Response:
        return httpx.Response(
            200,
            json={
                "1145360": {
                    "success": True,
                    "data": {
                        "name": "Hades",
                        "is_free": False,
                        "header_image": "https://example.com/header.jpg",
                        "price_overview": {
                            "currency": "RUB",
                            "initial": 88000,
                            "final": 26400,
                            "discount_percent": 70,
                        },
                    },
                }
            },
        )

    transport = httpx.MockTransport(handler)
    async with httpx.AsyncClient(transport=transport) as client:
        details = await get_steam_details(client, 1145360, _settings())

    assert details.name == "Hades"
    assert details.price_rub == 264.0
    assert details.discount_percent == 70
    assert details.available_in_ru is True
    assert details.store_url.endswith("/1145360/")


@pytest.mark.asyncio
async def test_get_steam_details_delisted_in_ru():
    def handler(request: httpx.Request) -> httpx.Response:
        url = str(request.url)
        if "cc=ru" in url:
            return httpx.Response(200, json={"1091500": {"success": False}})
        return httpx.Response(
            200,
            json={
                "1091500": {
                    "success": True,
                    "data": {
                        "name": "Cyberpunk 2077",
                        "header_image": "https://example.com/cp.jpg",
                        "price_overview": {
                            "currency": "USD",
                            "initial": 5999,
                            "final": 5999,
                            "discount_percent": 0,
                        },
                    },
                }
            },
        )

    transport = httpx.MockTransport(handler)
    async with httpx.AsyncClient(transport=transport) as client:
        details = await get_steam_details(client, 1091500, _settings())

    assert details.name == "Cyberpunk 2077"
    assert details.available_in_ru is False
    assert details.price_rub is None
    assert details.note is not None


def test_pick_best_candidate_exact_match():
    candidates = [
        SteamCandidate(appid=1, name="Hades II"),
        SteamCandidate(appid=2, name="Hades"),
        SteamCandidate(appid=3, name="Hades Original Soundtrack"),
    ]
    best = pick_best_candidate(candidates, "Hades")
    assert best is not None
    assert best.appid == 2
