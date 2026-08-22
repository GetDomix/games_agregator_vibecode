from __future__ import annotations

import httpx
import pytest

from app.config import Settings
from app.models import ProductKind
from app.services.plati import search_plati_offers


def _settings() -> Settings:
    return Settings(
        digiseller_partner_id="999",
        http_timeout_seconds=5.0,
        plati_max_pages=2,
        plati_page_size=2,
        ggsel_limit=10,
        steam_cc="ru",
        steam_lang="russian",
        currency="RUB",
    )


@pytest.mark.asyncio
async def test_search_plati_parses_and_classifies():
    page_payloads = {
        1: {
            "Pagenum": 1,
            "Pagesize": 2,
            "Totalpages": 2,
            "items": [
                {
                    "id": 1,
                    "name": "Hades Steam Key",
                    "price_rur": 300,
                    "url": "https://plati.market/itm/1",
                    "numsold": 100,
                    "seller_name": "A",
                    "description": "",
                },
                {
                    "id": 2,
                    "name": "Hades гифт",
                    "price_rur": 250,
                    "url": "https://plati.market/itm/2",
                    "numsold": 50,
                    "seller_name": "B",
                    "description": "",
                },
            ],
        },
        2: {
            "Pagenum": 2,
            "Pagesize": 2,
            "Totalpages": 2,
            "items": [
                {
                    "id": 3,
                    "name": "Hades аренда",
                    "price_rur": 100,
                    "url": "https://plati.market/itm/3",
                    "numsold": 10,
                    "seller_name": "C",
                    "description": "",
                },
            ],
        },
    }

    def handler(request: httpx.Request) -> httpx.Response:
        assert "search.ashx" in str(request.url)
        page = int(request.url.params.get("pagenum", "1"))
        return httpx.Response(200, json=page_payloads[page])

    transport = httpx.MockTransport(handler)
    async with httpx.AsyncClient(transport=transport) as client:
        offers, total, err = await search_plati_offers(client, "Hades", _settings())

    assert err is None
    assert len(offers) == 3
    assert total == 4  # Totalpages * page_size approx
    kinds = {o.kind for o in offers}
    assert ProductKind.KEY in kinds
    assert ProductKind.GIFT in kinds
    assert ProductKind.RENT in kinds
    # partner id appended
    assert offers[0].url.endswith("ai=999") or "ai=999" in offers[0].url


@pytest.mark.asyncio
async def test_search_plati_api_failure():
    def handler(request: httpx.Request) -> httpx.Response:
        return httpx.Response(500, text="boom")

    transport = httpx.MockTransport(handler)
    async with httpx.AsyncClient(transport=transport) as client:
        offers, total, err = await search_plati_offers(client, "Hades", _settings())

    assert offers == []
    assert total == 0
    assert err is not None
