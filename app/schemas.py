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


class PriceResponse(BaseModel):
    query: str
    steam: SteamPrice | None = None
    candidates: list[SteamCandidate] = Field(default_factory=list)
    plati: MarketplaceStats
    ggsel: MarketplaceStats
    warnings: list[str] = Field(default_factory=list)
    saved_to_history: bool = False
    is_favorite: bool = False


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
    password: str = Field(min_length=8, max_length=128)
    display_name: str | None = Field(default=None, max_length=80)

    @field_validator("password")
    @classmethod
    def password_strength(cls, v: str) -> str:
        if len(v.strip()) < 8:
            raise ValueError("Пароль не короче 8 символов")
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


class FavoriteCreate(BaseModel):
    appid: int
    game_name: str = Field(min_length=1, max_length=200)
    header_image: str | None = None
    target_price_rub: float | None = Field(default=None, ge=0)
    notes: str | None = Field(default=None, max_length=500)
    last_steam_price_rub: float | None = None


class FavoriteUpdate(BaseModel):
    target_price_rub: float | None = Field(default=None, ge=0)
    notes: str | None = Field(default=None, max_length=500)
    last_steam_price_rub: float | None = None


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


class PopularItem(BaseModel):
    query: str
    appid: int | None = None
    game_name: str | None = None
    header_image: str | None = None
    count: int = 0


class DashboardResponse(BaseModel):
    user: UserPublic
    recent_history: list[HistoryItem]
    favorites_preview: list[FavoriteItem]
    favorites_count: int
    searches_total: int
    searches_this_week: int
    alerts_count: int
    ctas: list[str] = Field(default_factory=list)


class TrendsResponse(BaseModel):
    items: list[PopularItem]
    source: str = "community"  # community | seed
