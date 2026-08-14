from aiogram.types import CallbackQuery, Message

from api import LaravelApiError, LaravelClient
from keyboards import alerts_keyboard, favorites_keyboard
from ui import format_alerts, format_favorites
from utils.telegram import actor, session


def handlers(api: LaravelClient):
    async def favorites(update: CallbackQuery | Message) -> None:
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
        await message.answer(format_favorites(items), parse_mode="HTML", reply_markup=favorites_keyboard(items) if items else None)

    async def alerts(update: CallbackQuery | Message, status: str = "active") -> None:
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
        await message.answer(format_alerts(items, status), parse_mode="HTML", reply_markup=alerts_keyboard(items, status))

    async def rearm(callback: CallbackQuery) -> None:
        try:
            await api.rearm(actor(callback), int(callback.data.split(":")[1]))
            await callback.answer("Alert снова активен")
            await alerts(callback, "triggered")
        except LaravelApiError as exc:
            await callback.answer(str(exc), show_alert=True)

    async def remove(callback: CallbackQuery) -> None:
        try:
            await api.remove_favorite(actor(callback), int(callback.data.split(":")[1]))
            await callback.answer("Убрано из избранного")
            await callback.message.answer("Игра убрана из общего избранного сайта и бота.")
        except LaravelApiError as exc:
            await callback.answer(str(exc), show_alert=True)

    return {"favorites": favorites, "alerts": alerts, "callback_alerts": lambda callback: alerts(callback, callback.data.split(":")[1]), "rearm": rearm, "remove": remove, "cmd_favorites": favorites, "cmd_alerts": alerts, "menu_favorites": favorites, "menu_alerts": alerts}
