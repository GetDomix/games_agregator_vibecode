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


def _router(request: httpx.Request) -> httpx.Response:
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
                        "tiny_image": "https://img/h.jpg",
                        "price": {"currency": "RUB", "initial": 88000, "final": 26400},
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
                        "header_image": "https://img/header.jpg",
                        "is_free": False,
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

    if "search.ashx" in url:
        return httpx.Response(
            200,
            json={
                "Pagenum": 1,
                "Pagesize": 10,
                "Totalpages": 1,
                "items": [
                    {
                        "id": 1,
                        "name": "Hades Key",
                        "price_rur": 300,
                        "url": "https://plati.market/itm/1",
                        "numsold": 20,
                        "seller_name": "P",
                        "description": "ключ",
                    }
                ],
            },
        )

    if "elastic/goods/query" in url:
        return httpx.Response(
            200,
            json={
                "data": {
                    "items": [
                        {
                            "id_goods": 7,
                            "url": "hades-7",
                            "is_active": True,
                            "name": "Hades",
                            "search_title": "gift",
                            "price_wmr": "150",
                            "cnt_sell": 5,
                            "seller_name": "G",
                            "content_type_id": 48,
                        }
                    ],
                    "total": 1,
                }
            },
        )

    return httpx.Response(404, text=f"unexpected url: {url}")


@pytest.mark.asyncio
async def test_aggregate_prices_end_to_end_mocked():
    transport = httpx.MockTransport(_router)
    async with httpx.AsyncClient(transport=transport) as client:
        result = await aggregate_prices(client, "Hades", _settings())

    assert result.steam is not None
    assert result.steam.appid == 1145360
    assert result.steam.price_rub == 264.0
    assert result.plati.scanned_offers == 1
    assert result.plati.by_kind[0].min_price == 300
    assert result.ggsel.scanned_offers == 1
    assert result.ggsel.by_kind[0].kind.value == "gift"
    assert result.warnings == []
