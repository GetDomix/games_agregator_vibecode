"""Extra unit/integration edges for steam, plati, ggsel parsers."""

from __future__ import annotations

import httpx
import pytest

from app.config import Settings
from app.services.ggsel import search_ggsel_offers
from app.services.plati import search_plati_offers
from app.services.steam import get_steam_details, search_steam
from app.models import ProductKind


def _settings(**kwargs) -> Settings:
    base = dict(
        digiseller_partner_id="42",
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
async def test_search_steam_skips_non_app_types():
    def handler(request: httpx.Request) -> httpx.Response:
        return httpx.Response(
            200,
            json={
                "items": [
                    {"type": "dlc", "name": "DLC Pack", "id": 1},
                    {"type": "bundle", "name": "Bundle", "id": 2},
                    {
                        "type": "app",
                        "name": "Real Game",
                        "id": 3,
                        "price": {"currency": "RUB", "initial": 10000, "final": 5000},
                    },
                    {"type": "app", "name": "", "id": 4},  # missing name
                    {"type": "app", "name": "NoId"},  # missing id
                ]
            },
        )

    transport = httpx.MockTransport(handler)
    async with httpx.AsyncClient(transport=transport) as client:
        result = await search_steam(client, "Real", _settings())

    assert len(result) == 1
    assert result[0].appid == 3
    assert result[0].price_rub == 50.0
    assert result[0].discount_percent == 50


@pytest.mark.asyncio
async def test_get_steam_details_completely_missing():
    def handler(request: httpx.Request) -> httpx.Response:
        return httpx.Response(200, json={"123": {"success": False}})

    transport = httpx.MockTransport(handler)
    async with httpx.AsyncClient(transport=transport) as client:
        details = await get_steam_details(client, 123, _settings(), fallback_name="Fallback")

    assert details.name == "Fallback"
    assert details.available_in_ru is False
    assert details.price_rub is None
    assert details.note is not None


@pytest.mark.asyncio
async def test_get_steam_details_free_game():
    def handler(request: httpx.Request) -> httpx.Response:
        return httpx.Response(
            200,
            json={
                "730": {
                    "success": True,
                    "data": {
                        "name": "Counter-Strike 2",
                        "is_free": True,
                        "header_image": "https://img/cs.jpg",
                    },
                }
            },
        )

    transport = httpx.MockTransport(handler)
    async with httpx.AsyncClient(transport=transport) as client:
        details = await get_steam_details(client, 730, _settings())

    assert details.is_free is True
    assert details.price_rub == 0.0
    assert details.available_in_ru is True


@pytest.mark.asyncio
async def test_plati_skips_zero_and_invalid_prices():
    def handler(request: httpx.Request) -> httpx.Response:
        return httpx.Response(
            200,
            json={
                "Pagenum": 1,
                "Pagesize": 20,
                "Totalpages": 1,
                "items": [
                    {"id": 1, "name": "bad", "price_rur": 0, "url": "https://p/1"},
                    {"id": 2, "name": "neg", "price_rur": -5, "url": "https://p/2"},
                    {"id": 3, "name": "nan", "price_rur": "nope", "url": "https://p/3"},
                    {
                        "id": 4,
                        "name": "ok key",
                        "price_rur": 99.9,
                        "url": "https://p/4",
                        "numsold": 3,
                        "seller_name": "S",
                    },
                ],
            },
        )

    transport = httpx.MockTransport(handler)
    async with httpx.AsyncClient(transport=transport) as client:
        offers, total, err = await search_plati_offers(client, "x", _settings())

    assert err is None
    assert len(offers) == 1
    assert offers[0].price_rub == 99.9
    assert offers[0].kind == ProductKind.KEY
    assert "ai=42" in offers[0].url


@pytest.mark.asyncio
async def test_ggsel_skips_invalid_prices_and_appends_partner():
    def handler(request: httpx.Request) -> httpx.Response:
        return httpx.Response(
            200,
            json={
                "data": {
                    "items": [
                        {
                            "id_goods": 1,
                            "is_active": True,
                            "name": "zero",
                            "price_wmr": "0",
                            "content_type_id": 2,
                        },
                        {
                            "id_goods": 2,
                            "url": "good-item",
                            "is_active": True,
                            "name": "Valid",
                            "search_title": "key",
                            "price_wmr": "12.5",
                            "cnt_sell": 1,
                            "content_type_id": 2,
                        },
                    ],
                    "total": 2,
                }
            },
        )

    transport = httpx.MockTransport(handler)
    async with httpx.AsyncClient(transport=transport) as client:
        offers, total, err = await search_ggsel_offers(client, "x", _settings())

    assert err is None
    assert total == 2
    assert len(offers) == 1
    assert offers[0].price_rub == 12.5
    assert "ai=42" in offers[0].url
    assert "good-item" in offers[0].url


@pytest.mark.asyncio
async def test_ggsel_list_body_shape():
    """API sometimes returns data as a bare list."""

    def handler(request: httpx.Request) -> httpx.Response:
        return httpx.Response(
            200,
            json={
                "data": [
                    {
                        "id": 9,
                        "is_active": True,
                        "name": "List shape",
                        "price_wmr": 10,
                        "content_type_id": 1,
                        "cnt_sell": 0,
                    }
                ]
            },
        )

    transport = httpx.MockTransport(handler)
    async with httpx.AsyncClient(transport=transport) as client:
        offers, total, err = await search_ggsel_offers(client, "x", _settings(digiseller_partner_id=""))

    assert err is None
    assert len(offers) == 1
    assert offers[0].kind == ProductKind.ACCOUNT
    assert total == 1
