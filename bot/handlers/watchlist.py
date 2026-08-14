from decimal import Decimal, InvalidOperation

from aiogram.fsm.context import FSMContext
from aiogram.types import CallbackQuery, Message

from api import LaravelApiError, LaravelClient
from keyboards import scopes_keyboard
from states import WatchSetup
from texts import SCOPE_LABELS
from utils.telegram import actor


def handlers(api: LaravelClient, show_card):
    async def begin(callback: CallbackQuery, state: FSMContext) -> None:
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
        await callback.message.answer("Выбери площадки и виды предложений. Аккаунты и аренда выключены по умолчанию.", reply_markup=scopes_keyboard(appid, selected))

    async def toggle(callback: CallbackQuery, state: FSMContext) -> None:
        data = await state.get_data()
        if not data.get("game"):
            await callback.answer("Настройка устарела — открой карточку снова.", show_alert=True)
            return
        _, appid, source, kind = callback.data.split(":")
        selected = {tuple(item) for item in data.get("scopes", [])}
        value = (source, kind)
        selected.remove(value) if value in selected else selected.add(value)
        await state.update_data(scopes=[list(item) for item in selected])
        await callback.message.edit_reply_markup(reply_markup=scopes_keyboard(int(appid), selected))
        await callback.answer()

    async def done(callback: CallbackQuery, state: FSMContext) -> None:
        if not (await state.get_data()).get("scopes"):
            await callback.answer("Выбери хотя бы один вариант.", show_alert=True)
            return
        await state.set_state(WatchSetup.entering_target)
        await callback.answer()
        await callback.message.answer("Введи целевую цену в рублях. Например: <code>999</code>\nИли отправь <code>-</code>, чтобы сохранить только отслеживание.", parse_mode="HTML")

    async def target(message: Message, state: FSMContext) -> None:
        data = await state.get_data()
        raw = (message.text or "").replace(" ", "").replace(",", ".")
        value = None
        if raw != "-":
            try:
                value = float(Decimal(raw))
                if value < 0 or value > 10_000_000:
                    raise InvalidOperation
            except (InvalidOperation, ValueError):
                await message.answer("Введи цену числом, например 999, или «-» без цели.")
                return
        scopes = [{"source": source, "offer_kind": kind} for source, kind in data.get("scopes", [])]
        try:
            favorite = await api.save_favorite(actor(message), data["game"], value, scopes)
        except LaravelApiError as exc:
            await message.answer(str(exc))
            return
        await state.clear()
        labels = ", ".join(SCOPE_LABELS[item["source"], item["offer_kind"]] for item in scopes)
        await message.answer(f"✅ Сохранено. Цель: <b>{value if value is not None else 'не задана'}</b>\nОтслеживаем: {labels}", parse_mode="HTML")
        await show_card(api, message, actor(message), favorite["appid"])

    return {"begin_watch": begin, "toggle_scope": toggle, "scope_done": done, "target": target}
