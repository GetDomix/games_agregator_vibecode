"""Unit tests for pure logic: rate limiter, security helpers, classifier edges, aggregator, persistence."""

from __future__ import annotations

from datetime import datetime, timedelta, timezone
from unittest.mock import MagicMock

import pytest

from app.auth.security import (
    create_access_token,
    decode_access_token,
    hash_password,
    verify_password,
)
from app.config import Settings
from app.db_models import Favorite
from app.schemas import OfferLink, ProductKind
from app.services.aggregator import _aggregate_by_kind, _marketplace_stats, aggregate_prices
from app.services.classifier import classify_from_text, classify_ggsel
from app.services.persistence import favorite_to_schema, is_favorite
from app.services.rate_limit import RateLimiter
from app.services.steam import pick_best_candidate
from app.models import SteamCandidate


# ── Password / JWT unit ─────────────────────────────────────────────────────


def test_password_hash_and_verify_roundtrip():
    h = hash_password("secret-pass-99")
    assert h != "secret-pass-99"
    assert verify_password("secret-pass-99", h) is True
    assert verify_password("wrong", h) is False


def test_verify_password_garbage_hash_returns_false():
    assert verify_password("x", "not-a-bcrypt-hash") is False
    assert verify_password("x", "") is False


def test_jwt_create_decode_roundtrip():
    settings = Settings(secret_key="unit-test-secret-key-xyz")
    token = create_access_token(user_id=42, settings=settings)
    assert decode_access_token(token, settings) == 42
    assert decode_access_token(token, Settings(secret_key="other")) is None
    assert decode_access_token("garbage", settings) is None


def test_jwt_rejects_non_numeric_sub():
    settings = Settings(secret_key="unit-test-secret-key-xyz")
    import jwt

    token = jwt.encode(
        {
            "sub": "not-an-int",
            "exp": datetime.now(timezone.utc) + timedelta(hours=1),
        },
        settings.secret_key,
        algorithm="HS256",
    )
    assert decode_access_token(token, settings) is None


# ── Rate limiter ────────────────────────────────────────────────────────────


def test_rate_limiter_allows_under_limit():
    lim = RateLimiter()
    for _ in range(3):
        assert lim.allow("k", limit=3) is True
    assert lim.allow("k", limit=3) is False


def test_rate_limiter_keys_are_independent():
    lim = RateLimiter()
    assert lim.allow("a", limit=1) is True
    assert lim.allow("a", limit=1) is False
    assert lim.allow("b", limit=1) is True


def test_rate_limiter_window_expiry():
    lim = RateLimiter()
    assert lim.allow("w", limit=1, window_seconds=0.05) is True
    assert lim.allow("w", limit=1, window_seconds=0.05) is False
    import time

    time.sleep(0.06)
    assert lim.allow("w", limit=1, window_seconds=0.05) is True


# ── Classifier edges ────────────────────────────────────────────────────────


def test_classify_empty_and_none_parts():
    assert classify_from_text("") == ProductKind.OTHER
    assert classify_from_text("  ") == ProductKind.OTHER


def test_classify_priority_gift_over_account():
    assert classify_from_text("gift account steam") == ProductKind.GIFT


def test_classify_priority_account_over_key():
    assert classify_from_text("аккаунт + ключ steam") == ProductKind.ACCOUNT


def test_classify_cyrillic_rent_stem_without_word_boundary():
    # "аренда" / "аренду" / "аренды" should all hit
    assert classify_from_text("аренду аккаунта 3 дня") == ProductKind.RENT
    assert classify_from_text("аренды игр") == ProductKind.RENT


def test_classify_ggsel_all_mapped_types():
    expected = {
        1: ProductKind.ACCOUNT,
        25: ProductKind.RENT,
        29: ProductKind.ACCOUNT,
        31: ProductKind.ACCOUNT,
        48: ProductKind.GIFT,
        56: ProductKind.GIFT,
        2: ProductKind.KEY,
        30: ProductKind.KEY,
        54: ProductKind.KEY,
    }
    for cid, kind in expected.items():
        assert classify_ggsel(cid, "ignored title") == kind


def test_classify_ggsel_none_content_type_uses_text():
    assert classify_ggsel(None, "Steam Key GLOBAL") == ProductKind.KEY


def test_classify_key_variants():
    assert classify_from_text("CD-KEY European") == ProductKind.KEY
    assert classify_from_text("лицензия steam") == ProductKind.KEY
    assert classify_from_text("GOG key") == ProductKind.KEY


# ── Aggregator stats edges ──────────────────────────────────────────────────


def _offer(kind: ProductKind, price: float, sales: int = 0, title: str = "x") -> OfferLink:
    return OfferLink(
        title=title,
        url=f"https://example.com/{title}",
        price_rub=price,
        sales=sales,
        kind=kind,
    )


def test_aggregate_popular_tie_breaks_on_lower_price():
    offers = [
        _offer(ProductKind.KEY, 200, sales=10, title="expensive"),
        _offer(ProductKind.KEY, 100, sales=10, title="cheap-same-sales"),
    ]
    stats = _aggregate_by_kind(offers)
    assert stats[0].popular is not None
    assert stats[0].popular.title == "cheap-same-sales"


def test_aggregate_empty_offers():
    assert _aggregate_by_kind([]) == []


def test_marketplace_stats_total_fallback_to_len():
    offers = [_offer(ProductKind.KEY, 10)]
    stats = _marketplace_stats("plati", "Plati", offers, total_offers=0, error=None)
    assert stats.total_offers == 1  # falls back to len(offers)
    assert stats.scanned_offers == 1
    assert len(stats.by_kind) == 1


def test_marketplace_stats_kind_order():
    offers = [
        _offer(ProductKind.OTHER, 1, title="o"),
        _offer(ProductKind.RENT, 2, title="r"),
        _offer(ProductKind.KEY, 3, title="k"),
        _offer(ProductKind.ACCOUNT, 4, title="a"),
        _offer(ProductKind.GIFT, 5, title="g"),
    ]
    kinds = [s.kind for s in _aggregate_by_kind(offers)]
    assert kinds == [
        ProductKind.KEY,
        ProductKind.GIFT,
        ProductKind.ACCOUNT,
        ProductKind.RENT,
        ProductKind.OTHER,
    ]


@pytest.mark.asyncio
async def test_aggregate_prices_empty_query_raises():
    import httpx

    async with httpx.AsyncClient() as client:
        with pytest.raises(ValueError, match="Пустой"):
            await aggregate_prices(client, "   ", Settings())


# ── pick_best_candidate ─────────────────────────────────────────────────────


def test_pick_best_startswith_and_contains():
    cands = [
        SteamCandidate(appid=1, name="Super Hades Deluxe"),
        SteamCandidate(appid=2, name="Hades II"),
        SteamCandidate(appid=3, name="Something Else"),
    ]
    assert pick_best_candidate(cands, "Hades").appid == 2  # startswith
    assert pick_best_candidate(cands, "Deluxe").appid == 1  # contains
    assert pick_best_candidate(cands, "zzz").appid == 1  # first fallback


def test_pick_best_empty():
    assert pick_best_candidate([], "x") is None


# ── Persistence helpers ─────────────────────────────────────────────────────


def test_favorite_to_schema_price_below_target_edges():
    now = datetime.now(timezone.utc)
    base = dict(
        id=1,
        appid=1,
        game_name="G",
        header_image=None,
        notes=None,
        created_at=now,
        updated_at=now,
    )
    # equal counts as below target
    row = Favorite(**base, target_price_rub=100, last_steam_price_rub=100)
    assert favorite_to_schema(row).price_below_target is True

    row2 = Favorite(**base, target_price_rub=100, last_steam_price_rub=101)
    assert favorite_to_schema(row2).price_below_target is False

    row3 = Favorite(**base, target_price_rub=None, last_steam_price_rub=50)
    assert favorite_to_schema(row3).price_below_target is False

    row4 = Favorite(**base, target_price_rub=100, last_steam_price_rub=None)
    assert favorite_to_schema(row4).price_below_target is False


def test_is_favorite_none_guards():
    db = MagicMock()
    assert is_favorite(db, None, 1) is False
    user = MagicMock()
    user.id = 1
    assert is_favorite(db, user, None) is False
    db.scalar.assert_not_called()
