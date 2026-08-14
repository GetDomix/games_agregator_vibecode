from aiogram import Bot, Dispatcher
from aiogram.client.default import DefaultBotProperties
from aiogram.enums import ParseMode

from api.laravel import LaravelClient
from config import get_settings


settings = get_settings()
bot = Bot(settings.bot_token, default=DefaultBotProperties(parse_mode=ParseMode.HTML))
dispatcher = Dispatcher()
api = LaravelClient(settings.api_base_url, settings.radar_service_token)
