from __future__ import annotations

from datetime import datetime, timezone

from sqlalchemy import (
    DateTime,
    Float,
    ForeignKey,
    Index,
    Integer,
    String,
    Text,
    UniqueConstraint,
)
from sqlalchemy.orm import Mapped, mapped_column, relationship

from app.db import Base


def utcnow() -> datetime:
    return datetime.now(timezone.utc)


class User(Base):
    __tablename__ = "users"

    id: Mapped[int] = mapped_column(Integer, primary_key=True, autoincrement=True)
    email: Mapped[str] = mapped_column(String(255), unique=True, index=True, nullable=False)
    password_hash: Mapped[str] = mapped_column(String(255), nullable=False)
    display_name: Mapped[str] = mapped_column(String(80), nullable=False, default="")
    created_at: Mapped[datetime] = mapped_column(DateTime(timezone=True), default=utcnow)
    last_login_at: Mapped[datetime | None] = mapped_column(DateTime(timezone=True), nullable=True)

    history: Mapped[list[SearchHistory]] = relationship(back_populates="user", cascade="all, delete-orphan")
    favorites: Mapped[list[Favorite]] = relationship(back_populates="user", cascade="all, delete-orphan")
    snapshots: Mapped[list[PriceSnapshot]] = relationship(back_populates="user", cascade="all, delete-orphan")


class SearchHistory(Base):
    __tablename__ = "search_history"
    __table_args__ = (Index("ix_history_user_created", "user_id", "created_at"),)

    id: Mapped[int] = mapped_column(Integer, primary_key=True, autoincrement=True)
    user_id: Mapped[int] = mapped_column(ForeignKey("users.id", ondelete="CASCADE"), nullable=False, index=True)
    query: Mapped[str] = mapped_column(String(200), nullable=False)
    appid: Mapped[int | None] = mapped_column(Integer, nullable=True)
    game_name: Mapped[str | None] = mapped_column(String(200), nullable=True)
    header_image: Mapped[str | None] = mapped_column(String(500), nullable=True)
    steam_price_rub: Mapped[float | None] = mapped_column(Float, nullable=True)
    plati_min_rub: Mapped[float | None] = mapped_column(Float, nullable=True)
    ggsel_min_rub: Mapped[float | None] = mapped_column(Float, nullable=True)
    created_at: Mapped[datetime] = mapped_column(DateTime(timezone=True), default=utcnow, index=True)

    user: Mapped[User] = relationship(back_populates="history")


class Favorite(Base):
    __tablename__ = "favorites"
    __table_args__ = (UniqueConstraint("user_id", "appid", name="uq_favorite_user_appid"),)

    id: Mapped[int] = mapped_column(Integer, primary_key=True, autoincrement=True)
    user_id: Mapped[int] = mapped_column(ForeignKey("users.id", ondelete="CASCADE"), nullable=False, index=True)
    appid: Mapped[int] = mapped_column(Integer, nullable=False)
    game_name: Mapped[str] = mapped_column(String(200), nullable=False)
    header_image: Mapped[str | None] = mapped_column(String(500), nullable=True)
    notes: Mapped[str | None] = mapped_column(Text, nullable=True)
    target_price_rub: Mapped[float | None] = mapped_column(Float, nullable=True)
    last_steam_price_rub: Mapped[float | None] = mapped_column(Float, nullable=True)
    created_at: Mapped[datetime] = mapped_column(DateTime(timezone=True), default=utcnow)
    updated_at: Mapped[datetime] = mapped_column(DateTime(timezone=True), default=utcnow, onupdate=utcnow)

    user: Mapped[User] = relationship(back_populates="favorites")


class PriceSnapshot(Base):
    __tablename__ = "price_snapshots"
    __table_args__ = (Index("ix_snap_user_appid_created", "user_id", "appid", "created_at"),)

    id: Mapped[int] = mapped_column(Integer, primary_key=True, autoincrement=True)
    user_id: Mapped[int] = mapped_column(ForeignKey("users.id", ondelete="CASCADE"), nullable=False, index=True)
    appid: Mapped[int | None] = mapped_column(Integer, nullable=True)
    steam_price_rub: Mapped[float | None] = mapped_column(Float, nullable=True)
    market_min_rub: Mapped[float | None] = mapped_column(Float, nullable=True)
    source_query: Mapped[str] = mapped_column(String(200), nullable=False, default="")
    created_at: Mapped[datetime] = mapped_column(DateTime(timezone=True), default=utcnow)

    user: Mapped[User] = relationship(back_populates="snapshots")


class PartnerClick(Base):
    """Affiliate / marketplace outbound click for monetization analytics."""

    __tablename__ = "partner_clicks"
    __table_args__ = (Index("ix_partner_clicks_created", "created_at"),)

    id: Mapped[int] = mapped_column(Integer, primary_key=True, autoincrement=True)
    user_id: Mapped[int | None] = mapped_column(
        ForeignKey("users.id", ondelete="SET NULL"), nullable=True, index=True
    )
    marketplace: Mapped[str] = mapped_column(String(40), nullable=False, default="unknown")
    url: Mapped[str] = mapped_column(String(1000), nullable=False, default="")
    appid: Mapped[int | None] = mapped_column(Integer, nullable=True)
    query: Mapped[str | None] = mapped_column(String(200), nullable=True)
    price_rub: Mapped[float | None] = mapped_column(Float, nullable=True)
    client_ip: Mapped[str | None] = mapped_column(String(64), nullable=True)
    created_at: Mapped[datetime] = mapped_column(DateTime(timezone=True), default=utcnow, index=True)


class DailySearchQuota(Base):
    """Server-side daily search counter for soft premium (auth by user, guest by IP)."""

    __tablename__ = "daily_search_quota"
    __table_args__ = (
        UniqueConstraint("quota_key", "day", name="uq_quota_key_day"),
        Index("ix_quota_day", "day"),
    )

    id: Mapped[int] = mapped_column(Integer, primary_key=True, autoincrement=True)
    quota_key: Mapped[str] = mapped_column(String(120), nullable=False)  # user:123 | ip:1.2.3.4
    day: Mapped[str] = mapped_column(String(10), nullable=False)  # YYYY-MM-DD UTC
    count: Mapped[int] = mapped_column(Integer, nullable=False, default=0)
    updated_at: Mapped[datetime] = mapped_column(DateTime(timezone=True), default=utcnow, onupdate=utcnow)
