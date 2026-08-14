from aiogram import F, Router
from aiogram.types import CallbackQuery, Message

from api import LaravelApiError
from keyboards import MENU_ALERTS, MENU_FAVORITES, alerts_keyboard, favorites_keyboard
from misc import api
from ui import format_alerts, format_favorites
from utils.telegram import actor, session


router = Router()


async def show_favorites(update: CallbackQuery | Message):
    message = update.message if isinstance(update, CallbackQuery) else update
    if isinstance(update, CallbackQuery):
        await update.answer()
    if not await session(api, update):
        return
    try:
        data = await api.favorites(actor(update))
    except LaravelApiError as exc:
        await message.answer(str(exc))
        return
    items = data.get("items") or []
    await message.answer(format_favorites(items), reply_markup=favorites_keyboard(items) if items else None)


async def show_alerts(update: CallbackQuery | Message, status: str = "active"):
    message = update.message if isinstance(update, CallbackQuery) else update
    if isinstance(update, CallbackQuery):
        await update.answer()
    if not await session(api, update):
        return
    try:
        data = await api.alerts(actor(update), status)
    except LaravelApiError as exc:
        await message.answer(str(exc))
        return
    items = data.get("items") or []
    await message.answer(format_alerts(items, status), reply_markup=alerts_keyboard(items, status))


@router.message(F.text == MENU_FAVORITES)
async def favorites_menu(message: Message):
    await show_favorites(message)


@router.message(F.text == MENU_ALERTS)
async def alerts_menu(message: Message):
    await show_alerts(message)


@router.callback_query(F.data == "favorites")
async def favorites_callback(callback: CallbackQuery):
    await show_favorites(callback)


@router.callback_query(F.data.startswith("alerts:"))
async def alerts_callback(callback: CallbackQuery):
    await show_alerts(callback, callback.data.split(":")[1])


@router.callback_query(F.data.startswith("rearm:"))
async def rearm_alert(callback: CallbackQuery):
    try:
        await api.rearm(actor(callback), int(callback.data.split(":")[1]))
        await callback.answer("Alert снова активен")
        await show_alerts(callback, "triggered")
    except LaravelApiError as exc:
        await callback.answer(str(exc), show_alert=True)


@router.callback_query(F.data.startswith("remove:"))
async def remove_favorite(callback: CallbackQuery):
    try:
        await api.remove_favorite(actor(callback), int(callback.data.split(":")[1]))
        await callback.answer("Убрано из избранного")
        await callback.message.answer("Игра убрана из общего избранного сайта и бота.")
    except LaravelApiError as exc:
        await callback.answer(str(exc), show_alert=True)
