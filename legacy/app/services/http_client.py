from __future__ import annotations

import httpx

from app.config import get_settings

DEFAULT_HEADERS = {
    "User-Agent": (
        "GamePriceAggregator/1.0 (+local; educational; "
        "https://github.com/local/agregator_games)"
    ),
    "Accept": "application/json, text/plain, */*",
}


def create_client() -> httpx.AsyncClient:
    settings = get_settings()
    return httpx.AsyncClient(
        timeout=httpx.Timeout(settings.http_timeout_seconds),
        headers=DEFAULT_HEADERS,
        follow_redirects=True,
    )
