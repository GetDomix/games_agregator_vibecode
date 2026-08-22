from __future__ import annotations

import httpx
import pytest

from app.config import Settings
from app.models import ProductKind
from app.services.ggsel import search_ggsel_offers


def _settings() -> Settings:
    return Settings(
        digiseller_partner_id="",
        http_timeout_seconds=5.0,
        plati_max_pages=1,
        plati_page_size=20,
        ggsel_limit=50,
        steam_cc="ru",
        steam_lang="russian",
        currency="RUB",
    )


@pytest.mark.asyncio
async def test_search_ggsel_parses_content_types():
    def handler(request: httpx.Request) -> httpx.Response:
        assert str(request.url).endswith("/elastic/goods/query")
        assert request.method == "POST"
        return httpx.Response(
            200,
            json={
                "data": {
                    "items": [
                        {
                            "id_goods": 10,
                            "url": "hades-key-10",
                            "is_active": True,
                            "name": "Hades",
                            "search_title": "Hades ключи Steam",
                            "price_wmr": "234.5",
                            "cnt_sell": 12,
                            "seller_name": "Shop",
                            "content_type_id": 2,
                        },
                        {
                            "id_goods": 11,
                            "url": "hades-acc-11",
                            "is_active": True,
                            "name": "Hades Acc",
                            "search_title": "аккаунты",
                            "price_wmr": "85",
                            "cnt_sell": 40,
                            "seller_name": "Shop2",
                            "content_type_id": 1,
                        },
                        {
                            "id_goods": 12,
                            "url": "inactive",
                            "is_active": False,
                            "name": "skip me",
                            "price_wmr": "1",
                            "cnt_sell": 0,
                            "content_type_id": 2,
                        },
                    ],
                    "total": 100,
                }
            },
        )

    transport = httpx.MockTransport(handler)
    async with httpx.AsyncClient(transport=transport) as client:
        offers, total, err = await search_ggsel_offers(client, "Hades", _settings())

    assert err is None
    assert total == 100
    assert len(offers) == 2
    assert offers[0].kind == ProductKind.KEY
    assert offers[0].price_rub == 234.5
    assert "ggsel.net/catalog/product/hades-key-10" in offers[0].url
    assert offers[1].kind == ProductKind.ACCOUNT


@pytest.mark.asyncio
async def test_search_ggsel_error():
    def handler(request: httpx.Request) -> httpx.Response:
        return httpx.Response(403, json={"error": "access denied"})

    transport = httpx.MockTransport(handler)
    async with httpx.AsyncClient(transport=transport) as client:
        offers, total, err = await search_ggsel_offers(client, "Hades", _settings())

    assert offers == []
    assert err is not None
