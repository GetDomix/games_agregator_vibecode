import os
from dataclasses import dataclass
from pathlib import Path

from dotenv import load_dotenv

load_dotenv(Path(__file__).with_name(".env"))


@dataclass(frozen=True)
class Settings:
    bot_token: str
    bot_username: str
    api_base_url: str
    radar_service_token: str


def get_settings() -> Settings:
    token = (os.getenv("TELEGRAM_BOT_TOKEN") or "").strip()
    if not token:
        raise RuntimeError("TELEGRAM_BOT_TOKEN is required")
    return Settings(
        bot_token=token,
        bot_username=(os.getenv("TELEGRAM_BOT_USERNAME") or "igroscan_radar_bot").lstrip("@"),
        api_base_url=(os.getenv("API_BASE_URL") or "http://127.0.0.1:8080").rstrip("/"),
        radar_service_token=(os.getenv("RADAR_SERVICE_TOKEN") or "").strip(),
    )
