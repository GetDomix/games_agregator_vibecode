from __future__ import annotations

from datetime import datetime
from enum import Enum
from typing import Any

from pydantic import BaseModel, EmailStr, Field, field_validator


class ProductKind(str, Enum):
    ACCOUNT = "account"
    GIFT = "gift"
    RENT = "rent"
    KEY = "key"
    OTHER = "other"


PRODUCT_KIND_LABELS: dict[ProductKind, str] = {
    ProductKind.ACCOUNT: "Аккаунт",
    ProductKind.GIFT: "Гифт",
    ProductKind.RENT: "Аренда",
    ProductKind.KEY: "Ключ",
    ProductKind.OTHER: "Другое",
}


class SteamCandidate(BaseModel):
    appid: int
    name: str
    header_image: str | None = None
    tiny_image: str | None = None
    price_rub: float | None = None
    price_initial_rub: float | None = None
    discount_percent: int = 0
    is_free: bool = False
    available_in_ru: bool = True


class OfferLink(BaseModel):
    title: str
    url: str
    price_rub: float
    sales: int = 0
    seller_name: str | None = None
    kind: ProductKind = ProductKind.OTHER


class KindStats(BaseModel):
    kind: ProductKind
    label: str
    count: int = 0
    min_price: float | None = None
    avg_price: float | None = None
    popular: OfferLink | None = None
    cheapest: OfferLink | None = None


class MarketplaceStats(BaseModel):
    marketplace: str
    label: str
    total_offers: int = 0
    scanned_offers: int = 0
    by_kind: list[KindStats] = Field(default_factory=list)
    error: str | None = None


class SteamPrice(BaseModel):
    appid: int
    name: str
    header_image: str | None = None
    store_url: str
    price_rub: float | None = None
    price_initial_rub: float | None = None
    discount_percent: int = 0
    is_free: bool = False
    available_in_ru: bool = True
    currency: str = "RUB"
    note: str | None = None


class DealScore(BaseModel):
    """How much cheaper market min is vs Steam — retention hook on results."""

    steam_price_rub: float | None = None
    market_min_rub: float | None = None
    market_source: str | None = None  # plati | ggsel
    savings_rub: float | None = None
    savings_percent: float | None = None
    score: int = 0  # 0–100, higher = better deal vs Steam
    label: str = ""  # short RU label for UI badge
    is_better: bool = False


class SearchQuotaInfo(BaseModel):
    limit: int
    used: int
    remaining: int
    is_guest: bool = True
    reset_hint: str = "обновится завтра (UTC)"


class PriceResponse(BaseModel):
    query: str
    steam: SteamPrice | None = None
    candidates: list[SteamCandidate] = Field(default_factory=list)
    plati: MarketplaceStats
    ggsel: MarketplaceStats
    warnings: list[str] = Field(default_factory=list)
    saved_to_history: bool = False
    is_favorite: bool = False
    deal: DealScore | None = None
    quota: SearchQuotaInfo | None = None


class SearchResponse(BaseModel):
    query: str
    candidates: list[SteamCandidate]
    meta: dict[str, Any] = Field(default_factory=dict)


class HealthResponse(BaseModel):
    status: str = "ok"
    db: str = "ok"
    version: str = "1.0.0"


class AdSlot(BaseModel):
    id: str
    placement: str
    format: str
    size_hint: str
    title: str
    subtitle: str
    cta: str = "Разместить рекламу"
    provider: str = "placeholder"
    html: str | None = None
    click_url: str | None = None
    image_url: str | None = None


class AdsConfigResponse(BaseModel):
    enabled: bool
    contact_email: str
    label: str
    note: str
    slots: list[AdSlot] = Field(default_factory=list)


# ── Auth ────────────────────────────────────────────────────────────────────


class UserPublic(BaseModel):
    id: int
    email: EmailStr
    display_name: str
    created_at: datetime
    last_login_at: datetime | None = None

    model_config = {"from_attributes": True}


class RegisterRequest(BaseModel):
    email: EmailStr
    # bcrypt only uses the first 72 bytes — cap so length is honest
    password: str = Field(min_length=8, max_length=72)
    display_name: str | None = Field(default=None, max_length=80)

    @field_validator("password")
    @classmethod
    def password_strength(cls, v: str) -> str:
        if len(v.strip()) < 8:
            raise ValueError("Пароль не короче 8 символов")
        # bcrypt silently truncates past 72 bytes
        if len(v.encode("utf-8")) > 72:
            raise ValueError("Пароль не длиннее 72 байт")
        return v


class LoginRequest(BaseModel):
    email: EmailStr
    password: str


class TokenResponse(BaseModel):
    access_token: str
    token_type: str = "bearer"
    user: UserPublic


class ProfileUpdateRequest(BaseModel):
    display_name: str = Field(min_length=1, max_length=80)

    @field_validator("display_name")
    @classmethod
    def display_name_not_blank(cls, v: str) -> str:
        cleaned = v.strip()
        if not cleaned:
            raise ValueError("Имя не может быть пустым")
        return cleaned[:80]


# ── History / favorites / dashboard ─────────────────────────────────────────


class HistoryItem(BaseModel):
    id: int
    query: str
    appid: int | None = None
    game_name: str | None = None
    header_image: str | None = None
    steam_price_rub: float | None = None
    plati_min_rub: float | None = None
    ggsel_min_rub: float | None = None
    created_at: datetime

    model_config = {"from_attributes": True}


class HistoryListResponse(BaseModel):
    items: list[HistoryItem]
    total: int


def _strip_nonblank(v: str, *, field: str = "value") -> str:
    cleaned = v.strip()
    if not cleaned:
        raise ValueError(f"{field} не может быть пустым")
    return cleaned


def _optional_http_url(v: str | None) -> str | None:
    if v is None:
        return None
    cleaned = v.strip()
    if not cleaned:
        return None
    if len(cleaned) > 500:
        raise ValueError("URL слишком длинный (макс. 500)")
    lower = cleaned.lower()
    if not (lower.startswith("http://") or lower.startswith("https://")):
        raise ValueError("URL должен начинаться с http:// или https://")
    return cleaned


class FavoriteCreate(BaseModel):
    appid: int = Field(ge=1)
    game_name: str = Field(min_length=1, max_length=200)
    header_image: str | None = Field(default=None, max_length=500)
    target_price_rub: float | None = Field(default=None, ge=0)
    notes: str | None = Field(default=None, max_length=500)
    last_steam_price_rub: float | None = Field(default=None, ge=0)

    @field_validator("game_name")
    @classmethod
    def game_name_not_blank(cls, v: str) -> str:
        return _strip_nonblank(v, field="game_name")[:200]

    @field_validator("header_image")
    @classmethod
    def header_image_http(cls, v: str | None) -> str | None:
        return _optional_http_url(v)


class FavoriteUpdate(BaseModel):
    target_price_rub: float | None = Field(default=None, ge=0)
    notes: str | None = Field(default=None, max_length=500)
    last_steam_price_rub: float | None = Field(default=None, ge=0)


class FavoriteItem(BaseModel):
    id: int
    appid: int
    game_name: str
    header_image: str | None = None
    notes: str | None = None
    target_price_rub: float | None = None
    last_steam_price_rub: float | None = None
    price_below_target: bool = False
    created_at: datetime
    updated_at: datetime

    model_config = {"from_attributes": True}


class FavoritesListResponse(BaseModel):
    items: list[FavoriteItem]
    total: int
    price_hits: list[FavoriteItem] = Field(default_factory=list)


class WatchlistRefreshItem(BaseModel):
    appid: int
    game_name: str
    ok: bool
    last_steam_price_rub: float | None = None
    target_price_rub: float | None = None
    price_below_target: bool = False
    market_min_rub: float | None = None
    error: str | None = None


class WatchlistRefreshResponse(BaseModel):
    refreshed: list[WatchlistRefreshItem]
    skipped: int = 0
    message: str = ""


class PopularItem(BaseModel):
    query: str
    appid: int | None = None
    game_name: str | None = None
    header_image: str | None = None
    count: int = 0
    steam_price_rub: float | None = None
    market_min_rub: float | None = None
    savings_percent: float | None = None


class HotDealItem(BaseModel):
    query: str
    appid: int | None = None
    game_name: str | None = None
    header_image: str | None = None
    steam_price_rub: float | None = None
    market_min_rub: float | None = None
    savings_percent: float | None = None
    savings_rub: float | None = None


class HotDealsResponse(BaseModel):
    items: list[HotDealItem]
    source: str = "community"  # community | seed


class DashboardResponse(BaseModel):
    user: UserPublic
    recent_history: list[HistoryItem]
    favorites_preview: list[FavoriteItem]
    price_hits: list[FavoriteItem] = Field(default_factory=list)
    favorites_count: int
    searches_total: int
    searches_this_week: int
    alerts_count: int
    ctas: list[str] = Field(default_factory=list)
    quota: SearchQuotaInfo | None = None


class TrendsResponse(BaseModel):
    items: list[PopularItem]
    source: str = "community"  # community | seed


class PartnerClickRequest(BaseModel):
    url: str = Field(min_length=1, max_length=1000)
    marketplace: str = Field(default="unknown", max_length=40)
    appid: int | None = None
    query: str | None = Field(default=None, max_length=200)
    price_rub: float | None = Field(default=None, ge=0)


class PartnerClickResponse(BaseModel):
    ok: bool = True
    id: int | None = None


class QuotaStatusResponse(BaseModel):
    quota: SearchQuotaInfo
