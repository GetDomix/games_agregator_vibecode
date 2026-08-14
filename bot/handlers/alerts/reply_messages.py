from aiogram import Router
from aiogram.types import Message

from keyboards.alerts import alerts_keyboard
from keyboards.watchlist import favorites_keyboard
from misc import api
from ui import format_alerts, format_favorites
from utils.telegram import actor

router = Router()


async def show_favorites(message: Message, edit: bool = False):
    data = await api.favorites(actor(message))
    items = data.get("items") or []
    text = format_favorites(items)
    markup = favorites_keyboard(items) if items else None
    if edit:
        await message.edit_text(text, reply_markup=markup)
    else:
        await message.answer(text, reply_markup=markup)


async def show_alerts(message: Message, status: str = "active", edit: bool = False):
    data = await api.alerts(actor(message), status)
    text = format_alerts(data.get("items") or [], status)
    markup = alerts_keyboard(data.get("items") or [], status)
    if edit:
        await message.edit_text(text, reply_markup=markup)
    else:
        await message.answer(text, reply_markup=markup)
