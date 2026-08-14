from aiogram import F, Router
from aiogram.types import CallbackQuery

from api.laravel import LaravelApiError
from decorators.session import session
from misc import api
from utils.telegram import actor
from .reply_messages import show_alerts, show_favorites

router = Router()


@router.callback_query(F.data == "menu_favorites")
@session
async def favorites_menu(callback: CallbackQuery):
    await callback.answer()
    await show_favorites(callback.message, edit=True)


@router.callback_query(F.data == "menu_alerts")
@session
async def alerts_menu(callback: CallbackQuery):
    await callback.answer()
    await show_alerts(callback.message, edit=True)


@router.callback_query(F.data == "favorites")
@session
async def favorites_callback(callback: CallbackQuery):
    await callback.answer()
    await show_favorites(callback.message, edit=True)


@router.callback_query(F.data.startswith("alerts:"))
@session
async def alerts_callback(callback: CallbackQuery):
    await callback.answer()
    await show_alerts(callback.message, callback.data.split(":")[1], edit=True)


@router.callback_query(F.data.startswith("rearm:"))
@session
async def rearm_alert(callback: CallbackQuery):
    try:
        await api.rearm(actor(callback), int(callback.data.split(":")[1]))
        await callback.answer("Alert снова активен")
        await show_alerts(callback.message, "triggered", edit=True)
    except LaravelApiError as exc:
        await callback.answer(str(exc), show_alert=True)


@router.callback_query(F.data.startswith("remove:"))
@session
async def remove_favorite(callback: CallbackQuery):
    await api.remove_favorite(actor(callback), int(callback.data.split(":")[1]))
    await callback.answer("Убрано из избранного")
