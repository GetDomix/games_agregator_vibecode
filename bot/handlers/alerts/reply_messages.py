from aiogram import F, Router
from aiogram.types import Message

from keyboards.alerts import alerts_keyboard
from keyboards.watchlist import favorites_keyboard
from misc import api
from ui import format_alerts, format_favorites
from utils.telegram import actor, session

router = Router()


async def show_favorites(message: Message):
    if not await session(api, message):
        return
    data = await api.favorites(actor(message))
    items = data.get("items") or []
    await message.answer(format_favorites(items), reply_markup=favorites_keyboard(items) if items else None)


async def show_alerts(message: Message, status: str = "active"):
    if not await session(api, message):
        return
    data = await api.alerts(actor(message), status)
    await message.answer(format_alerts(data.get("items") or [], status), reply_markup=alerts_keyboard(data.get("items") or [], status))


@router.message(F.text == "📚 Избранное")
async def favorites_menu(message: Message):
    await show_favorites(message)


@router.message(F.text == "🔔 Алерты")
async def alerts_menu(message: Message):
    await show_alerts(message)
