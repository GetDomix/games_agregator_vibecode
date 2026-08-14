from decimal import Decimal, InvalidOperation

from aiogram import F, Router
from aiogram.fsm.context import FSMContext
from aiogram.types import CallbackQuery

from api.laravel import LaravelApiError
from decorators.session import session
from keyboards.cards import scopes_keyboard
from misc import api
from utils.card import show_card
from utils.telegram import actor
from .states import WatchSetup

router = Router()


@router.callback_query(F.data.startswith("watch:"))
@session
async def begin_watch(callback: CallbackQuery, state: FSMContext):
    await callback.answer()
    appid = int(callback.data.split(":")[1])
    try:
        payload = await api.card(actor(callback), appid)
    except LaravelApiError as exc:
        await callback.message.answer(str(exc))
        return
    steam = payload["card"].get("steam") or {}
    selected = {("steam", "official")}
    for item in (payload.get("favorite") or {}).get("alert", {}).get("scopes", []):
        selected.add((item["source"], item["offer_kind"]))
    await state.set_state(WatchSetup.choosing_scopes)
    await state.set_data(game={"appid": appid, "name": steam.get("name") or "Игра", "header_image": steam.get("header_image")}, scopes=[list(item) for item in selected])
    await callback.message.answer("Выбери площадки и виды предложений.", reply_markup=scopes_keyboard(appid, selected))


@router.callback_query(F.data.startswith("scope:"))
@session
async def toggle_scope(callback: CallbackQuery, state: FSMContext):
    data = await state.get_data()
    if not data.get("game"):
        await callback.answer("Настройка устарела — открой карточку снова.", show_alert=True)
        return
    _, appid, source, kind = callback.data.split(":")
    selected = {tuple(item) for item in data.get("scopes", [])}
    value = (source, kind)
    if value in selected:
        selected.remove(value)
    else:
        selected.add(value)
    await state.update_data(scopes=[list(item) for item in selected])
    await callback.message.edit_reply_markup(reply_markup=scopes_keyboard(int(appid), selected))
    await callback.answer()


@router.callback_query(F.data.startswith("scope_done:"))
@session
async def finish_scope(callback: CallbackQuery, state: FSMContext):
    if not (await state.get_data()).get("scopes"):
        await callback.answer("Выбери хотя бы один вариант.", show_alert=True)
        return
    await state.set_state(WatchSetup.entering_target)
    await callback.answer()
    await callback.message.answer("Введи целевую цену в рублях или отправь «-» без цели.")


@router.message(WatchSetup.entering_target)
@session
async def save_target(message, state: FSMContext):
    data = await state.get_data()
    raw = (message.text or "").replace(" ", "").replace(",", ".")
    value = None
    if raw != "-":
        try:
            value = float(Decimal(raw))
            if value < 0 or value > 10_000_000:
                raise InvalidOperation
        except (InvalidOperation, ValueError):
            await message.answer("Введи цену числом или отправь «-» без цели.")
            return
    scopes = [{"source": source, "offer_kind": kind} for source, kind in data.get("scopes", [])]
    try:
        favorite = await api.save_favorite(actor(message), data["game"], value, scopes)
    except LaravelApiError as exc:
        await message.answer(str(exc))
        return
    await state.clear()
    text = f'''
✅ Сохранено.
Цель: <b>{value if value is not None else 'не задана'}</b>
'''
    await message.answer(text)
    await show_card(message, favorite["appid"])
