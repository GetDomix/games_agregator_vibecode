from app.config import Settings
from app.services.ads import build_ads_config
from httpx import ASGITransport, AsyncClient
from unittest.mock import MagicMock
import pytest

from app.main import app


def test_build_ads_config_enabled():
    cfg = build_ads_config(
        Settings(
            ads_enabled=True,
            ads_contact_email="sales@test.local",
            ads_label="Реклама",
        )
    )
    assert cfg.enabled is True
    assert cfg.contact_email == "sales@test.local"
    assert len(cfg.slots) >= 4
    placements = {s.placement for s in cfg.slots}
    assert {"header", "mid", "footer", "inline_results"} <= placements
    assert all(s.provider == "placeholder" for s in cfg.slots)
    assert "sales@test.local" in (cfg.slots[0].click_url or "")


def test_build_ads_config_disabled():
    cfg = build_ads_config(Settings(ads_enabled=False, ads_contact_email="x@y.z"))
    assert cfg.enabled is False
    assert cfg.slots == []


@pytest.fixture
async def api_client():
    app.state.http = MagicMock(name="http_client")
    transport = ASGITransport(app=app)
    async with AsyncClient(transport=transport, base_url="http://test") as client:
        yield client


@pytest.mark.asyncio
async def test_ads_config_endpoint(api_client: AsyncClient):
    resp = await api_client.get("/api/ads/config")
    assert resp.status_code == 200
    body = resp.json()
    assert "enabled" in body
    assert "slots" in body
    assert "contact_email" in body
    if body["enabled"]:
        assert len(body["slots"]) >= 1
        assert body["slots"][0]["id"]
