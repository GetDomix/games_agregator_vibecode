from aiogram import F, Router
from aiogram.types import CallbackQuery

from misc import api
from utils.telegram import actor
from .reply_messages import show_alerts, show_favorites

router = Router()


@router.callback_query(F.data == "favorites")
async def favorites_callback(callback: CallbackQuery):
    await callback.answer()
    await show_favorites(callback.message)


@router.callback_query(F.data.startswith("alerts:"))
async def alerts_callback(callback: CallbackQuery):
    await callback.answer()
    await show_alerts(callback.message, callback.data.split(":")[1])


@router.callback_query(F.data.startswith("rearm:"))
async def rearm_alert(callback: CallbackQuery):
    await api.rearm(actor(callback), int(callback.data.split(":")[1]))
    await callback.answer("Alert снова активен")
    await show_alerts(callback.message, "triggered")


@router.callback_query(F.data.startswith("remove:"))
async def remove_favorite(callback: CallbackQuery):
    await api.remove_favorite(actor(callback), int(callback.data.split(":")[1]))
    await callback.answer("Убрано из избранного")
