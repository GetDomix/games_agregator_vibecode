from functools import lru_cache

from pydantic_settings import BaseSettings, SettingsConfigDict


class Settings(BaseSettings):
    model_config = SettingsConfigDict(
        env_file=".env",
        env_file_encoding="utf-8",
        extra="ignore",
    )

    app_name: str = "Game Price Aggregator"
    app_env: str = "development"  # development | production
    app_version: str = "1.0.0"

    digiseller_partner_id: str = ""
    http_timeout_seconds: float = 20.0
    plati_max_pages: int = 5
    plati_page_size: int = 100
    ggsel_limit: int = 100

    steam_cc: str = "ru"
    steam_lang: str = "russian"
    currency: str = "RUB"

    # Monetization placeholders
    ads_enabled: bool = True
    ads_contact_email: str = "ads@example.com"
    ads_label: str = "Реклама"

    # Soft premium search quotas (per UTC day)
    free_searches_per_day: int = 15
    guest_searches_per_day: int = 5
    # Watchlist bulk refresh
    watchlist_refresh_max: int = 5
    rate_limit_watchlist_refresh_per_hour: int = 3

    # Auth / DB
    secret_key: str = "dev-change-me-in-production-please-use-long-random"
    access_token_expire_minutes: int = 60 * 24 * 7  # 7 days
    database_url: str = "sqlite:///./data/app.db"
    cors_origins: str = "*"  # comma-separated or *

    # Light in-memory rate limits
    rate_limit_login_per_minute: int = 20
    rate_limit_prices_per_minute: int = 60


@lru_cache
def get_settings() -> Settings:
    return Settings()
