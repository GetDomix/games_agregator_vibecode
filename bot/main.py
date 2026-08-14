"""Игроскан — тонкий Telegram-интерфейс общего аккаунта Laravel."""

from __future__ import annotations

import asyncio
import logging
import re
from decimal import Decimal, InvalidOperation

from aiogram import Bot, Dispatcher
from aiogram.enums import ChatAction
from aiogram.filters import CommandObject
from aiogram.fsm.context import FSMContext
from aiogram.types import BufferedInputFile, CallbackQuery, Message

from api import LaravelApiError, LaravelClient
from config import get_settings
from handlers.registry import register_handlers
from services import render_card
from states import WatchSetup
from ui import (MENU_ALERTS, MENU_FAVORITES, MENU_HELP, MENU_HOME, MENU_SEARCH,
                SCOPE_LABELS, alerts_keyboard, candidates_keyboard, card_keyboard,
                favorites_keyboard, format_alerts, format_card_details, format_favorites,
                main_menu_keyboard, scopes_keyboard)
from utils.telegram import actor, private, session

logging.basicConfig(level=logging.INFO, format="%(asctime)s %(levelname)s %(name)s: %(message)s")
log = logging.getLogger("igroscan-bot")
CODE_RE = re.compile(r"^[A-Za-z0-9]{6,16}$")


async def show_main_menu(message: Message) -> None:
    await message.answer(
        "👋 <b>Игроскан</b>\n\nВыбери действие кнопкой или напиши название игры — я покажу сохранённую карточку цен.",
        parse_mode="HTML",
        reply_markup=main_menu_keyboard(),
    )


async def show_card(api: LaravelClient, message: Message, telegram_user_id: int, appid: int, query: str | None = None) -> None:
    await message.bot.send_chat_action(message.chat.id, ChatAction.TYPING)
    try:
        payload = await api.card(telegram_user_id, appid, query)
    except LaravelApiError as exc:
        await message.answer(str(exc))
        return
    card, favorite = payload["card"], payload.get("favorite")
    await message.bot.send_chat_action(message.chat.id, ChatAction.UPLOAD_PHOTO)
    cover = await api.image((card.get("steam") or {}).get("header_image"))
    image = render_card(card, cover)
    await message.answer_photo(
        BufferedInputFile(image, filename=f"igroscan-{appid}.png"),
        caption=format_card_details(card, favorite),
        parse_mode="HTML",
        reply_markup=card_keyboard(appid, favorite is not None),
    )


def make_handlers(api: LaravelClient):
    async def cmd_start(message: Message, command: CommandObject) -> None:
        if not private(message):
            await message.answer("Открой бота в личном чате, чтобы привязать аккаунт.")
            return
        code = (command.args or "").strip().upper()
        if code:
            if not CODE_RE.match(code):
                await message.answer("Код выглядит странно. Скопируй его с сайта целиком.")
                return
            try:
                data = await api.bind_telegram(code, message.from_user.id, message.chat.id, message.from_user.username)
                await api.session(message.from_user.id, message.chat.id, message.from_user.username, message.from_user.full_name)
                await message.answer(f"✅ Привязка готова, <b>{data.get('display_name') or 'игрок'}</b>. Данные сайта и бота теперь общие.", parse_mode="HTML")
            except LaravelApiError as exc:
                await message.answer(f"Не вышло привязать: {exc}")
                return
        elif not await session(api, message):
            return
        await show_main_menu(message)

    async def cmd_help(message: Message) -> None:
        await message.answer("<b>Как пользоваться</b>\n\nВыбери «Найти игру» и напиши название. На карточке можно добавить игру в общее избранное, выбрать виды предложений и задать цену.\n\n«Избранное» и «Алерты» показывают те же данные, что и сайт.", parse_mode="HTML", reply_markup=main_menu_keyboard())

    async def cmd_search(message: Message, state: FSMContext) -> None:
        if not await session(api, message):
            return
        await state.clear()
        await message.answer("Напиши название игры. Например: <i>Hades</i>", parse_mode="HTML")

    async def text_search(message: Message, state: FSMContext) -> None:
        if not private(message) or not message.text or message.text.startswith("/"):
            return
        if not await session(api, message):
            return
        query = message.text.strip()
        if len(query) < 2:
            await message.answer("Нужно хотя бы две буквы названия.")
            return
        await message.bot.send_chat_action(message.chat.id, ChatAction.TYPING)
        try:
            result = await api.search(actor(message), query)
        except LaravelApiError as exc:
            await message.answer(str(exc))
            return
        candidates = result.get("candidates") or []
        if not candidates:
            await message.answer("Ничего не нашёл. Попробуй другое название.")
            return
        await state.update_data(query=query)
        await message.answer("Выбери игру:", reply_markup=candidates_keyboard(candidates))

    async def pick(callback: CallbackQuery, state: FSMContext) -> None:
        await callback.answer()
        data = await state.get_data()
        await show_card(api, callback.message, actor(callback), int(callback.data.split(":")[1]), data.get("query"))

    async def refresh_card(callback: CallbackQuery, state: FSMContext) -> None:
        await callback.answer("Собираю сохранённые цены…")
        data = await state.get_data()
        await show_card(api, callback.message, actor(callback), int(callback.data.split(":")[1]), data.get("query"))

    async def begin_watch(callback: CallbackQuery, state: FSMContext) -> None:
        await callback.answer()
        appid = int(callback.data.split(":")[1])
        try:
            payload = await api.card(actor(callback), appid)
        except LaravelApiError as exc:
            await callback.message.answer(str(exc))
            return
        steam = payload["card"].get("steam") or {}
        selected = {("steam", "official")}
        favorite = payload.get("favorite") or {}
        for item in (favorite.get("alert") or {}).get("scopes") or []:
            selected.add((item["source"], item["offer_kind"]))
        await state.set_state(WatchSetup.choosing_scopes)
        await state.set_data(game={"appid": appid, "name": steam.get("name") or "Игра", "header_image": steam.get("header_image")}, scopes=[list(item) for item in selected])
        await callback.message.answer("Выбери площадки и виды предложений. Аккаунты и аренда выключены по умолчанию.", reply_markup=scopes_keyboard(appid, selected))

    async def toggle_scope(callback: CallbackQuery, state: FSMContext) -> None:
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

    async def scope_done(callback: CallbackQuery, state: FSMContext) -> None:
        data = await state.get_data()
        if not data.get("scopes"):
            await callback.answer("Выбери хотя бы один вариант.", show_alert=True)
            return
        await state.set_state(WatchSetup.entering_target)
        await callback.answer()
        await callback.message.answer("Введи целевую цену в рублях. Например: <code>999</code>\nИли отправь <code>-</code>, чтобы сохранить только отслеживание.", parse_mode="HTML")

    async def target(message: Message, state: FSMContext) -> None:
        data = await state.get_data()
        raw = (message.text or "").replace(" ", "").replace(",", ".")
        value: float | None = None
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
        labels = ", ".join(SCOPE_LABELS[(item["source"], item["offer_kind"])] for item in scopes)
        await message.answer(f"✅ Сохранено. Цель: <b>{value if value is not None else 'не задана'}</b>\nОтслеживаем: {labels}", parse_mode="HTML")
        await show_card(api, message, actor(message), favorite["appid"])

    async def favorites(callback_or_message: CallbackQuery | Message) -> None:
        message = callback_or_message.message if isinstance(callback_or_message, CallbackQuery) else callback_or_message
        if isinstance(callback_or_message, CallbackQuery):
            await callback_or_message.answer()
        if not await session(api, callback_or_message):
            return
        try:
            data = await api.favorites(actor(callback_or_message))
        except LaravelApiError as exc:
            await message.answer(str(exc))
            return
        items = data.get("items") or []
        await message.answer(format_favorites(items), parse_mode="HTML", reply_markup=favorites_keyboard(items) if items else None)

    async def alerts(callback_or_message: CallbackQuery | Message, status: str = "active") -> None:
        message = callback_or_message.message if isinstance(callback_or_message, CallbackQuery) else callback_or_message
        if isinstance(callback_or_message, CallbackQuery):
            await callback_or_message.answer()
        if not await session(api, callback_or_message):
            return
        try:
            data = await api.alerts(actor(callback_or_message), status)
        except LaravelApiError as exc:
            await message.answer(str(exc))
            return
        items = data.get("items") or []
        await message.answer(format_alerts(items, status), parse_mode="HTML", reply_markup=alerts_keyboard(items, status))

    async def callback_alerts(callback: CallbackQuery) -> None:
        await alerts(callback, callback.data.split(":")[1])

    async def rearm(callback: CallbackQuery) -> None:
        appid = int(callback.data.split(":")[1])
        try:
            await api.rearm(actor(callback), appid)
            await callback.answer("Alert снова активен")
            await alerts(callback, "triggered")
        except LaravelApiError as exc:
            await callback.answer(str(exc), show_alert=True)

    async def remove(callback: CallbackQuery) -> None:
        appid = int(callback.data.split(":")[1])
        try:
            await api.remove_favorite(actor(callback), appid)
            await callback.answer("Убрано из избранного")
            await callback.message.answer("Игра убрана из общего избранного сайта и бота.")
        except LaravelApiError as exc:
            await callback.answer(str(exc), show_alert=True)

    async def cmd_favorites(message: Message) -> None:
        await favorites(message)

    async def cmd_alerts(message: Message) -> None:
        await alerts(message)

    async def menu_search(message: Message, state: FSMContext) -> None:
        await cmd_search(message, state)

    async def menu_favorites(message: Message) -> None:
        await favorites(message)

    async def menu_alerts(message: Message) -> None:
        await alerts(message)

    async def menu_help(message: Message) -> None:
        await cmd_help(message)

    async def menu_home(message: Message) -> None:
        if await session(api, message):
            await show_main_menu(message)

    return locals()


async def main() -> None:
    settings = get_settings()
    bot, dp, api = Bot(token=settings.bot_token), Dispatcher(), LaravelClient(settings.api_base_url, settings.radar_service_token)
    handlers = make_handlers(api)
    register_handlers(dp, handlers)
    log.info("Bot @%s starting", settings.bot_username)
    await dp.start_polling(bot)


if __name__ == "__main__":
    asyncio.run(main())
