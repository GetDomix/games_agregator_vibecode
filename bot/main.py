"""Игроскан — тонкий Telegram-интерфейс общего аккаунта Laravel."""

from __future__ import annotations

import asyncio
import logging
import re
from decimal import Decimal, InvalidOperation

from aiogram import Bot, Dispatcher, F
from aiogram.enums import ChatAction, ChatType
from aiogram.filters import Command, CommandObject, CommandStart
from aiogram.fsm.context import FSMContext
from aiogram.fsm.state import State, StatesGroup
from aiogram.types import BufferedInputFile, CallbackQuery, Message

from api_client import LaravelApiError, LaravelClient
from card_renderer import render_card
from config import get_settings
from ui import (SCOPE_LABELS, alerts_keyboard, candidates_keyboard, card_keyboard,
                favorites_keyboard, format_alerts, format_card_details, format_favorites,
                scopes_keyboard)

logging.basicConfig(level=logging.INFO, format="%(asctime)s %(levelname)s %(name)s: %(message)s")
log = logging.getLogger("igroscan-bot")
CODE_RE = re.compile(r"^[A-Za-z0-9]{6,16}$")


class WatchSetup(StatesGroup):
    choosing_scopes = State()
    entering_target = State()


def message_of(update: Message | CallbackQuery) -> Message:
    return update.message if isinstance(update, CallbackQuery) else update


def private(update: Message | CallbackQuery) -> bool:
    return message_of(update).chat.type == ChatType.PRIVATE and update.from_user is not None


async def session(api: LaravelClient, update: Message | CallbackQuery) -> bool:
    message = message_of(update)
    if not private(update):
        await message.answer("Для безопасности открой Игроскан в личном чате с ботом.")
        return False
    try:
        await api.session(update.from_user.id, message.chat.id, update.from_user.username, update.from_user.full_name)
        return True
    except LaravelApiError as exc:
        await message.answer(str(exc))
        return False


def actor(update: Message | CallbackQuery) -> int:
    return update.from_user.id


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
    await message.answer_photo(BufferedInputFile(image, filename=f"igroscan-{appid}.png"), caption="Игроскан · цены из серверного хранилища")
    await message.answer(format_card_details(card, favorite), parse_mode="HTML", disable_web_page_preview=True,
                         reply_markup=card_keyboard(appid, favorite is not None))


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
                data = await api.bind_telegram(code, message.chat.id, message.from_user.username)
                await api.session(message.from_user.id, message.chat.id, message.from_user.username, message.from_user.full_name)
                await message.answer(f"✅ Привязка готова, <b>{data.get('display_name') or 'игрок'}</b>. Данные сайта и бота теперь общие.", parse_mode="HTML")
            except LaravelApiError as exc:
                await message.answer(f"Не вышло привязать: {exc}")
                return
        elif not await session(api, message):
            return
        await message.answer("👋 <b>Игроскан</b>\n\nНапиши название игры — я покажу карточку цен Steam, Plati и GGsel.\n\nКоманды: /search, /favorites, /alerts, /help", parse_mode="HTML")

    async def cmd_help(message: Message) -> None:
        await message.answer("<b>Игроскан в Telegram</b>\n\n/search — найти игру\n/favorites — общее избранное\n/alerts — активные и сработавшие alert-ы\n\nПосле поиска выбери игру: бот пришлёт фото-карточку и даст настроить площадки, виды предложений и целевую цену.", parse_mode="HTML")

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

    return locals()


async def main() -> None:
    settings = get_settings()
    bot, dp, api = Bot(token=settings.bot_token), Dispatcher(), LaravelClient(settings.api_base_url, settings.radar_service_token)
    handlers = make_handlers(api)
    dp.message.register(handlers["cmd_start"], CommandStart())
    dp.message.register(handlers["cmd_help"], Command("help"))
    dp.message.register(handlers["cmd_search"], Command("search"))
    dp.message.register(handlers["cmd_favorites"], Command("favorites"))
    dp.message.register(handlers["cmd_alerts"], Command("alerts"))
    dp.callback_query.register(handlers["pick"], F.data.startswith("pick:"))
    dp.callback_query.register(handlers["refresh_card"], F.data.startswith("card:"))
    dp.callback_query.register(handlers["begin_watch"], F.data.startswith("watch:"))
    dp.callback_query.register(handlers["toggle_scope"], F.data.startswith("scope:"))
    dp.callback_query.register(handlers["scope_done"], F.data.startswith("scope_done:"))
    dp.callback_query.register(handlers["favorites"], F.data == "favorites")
    dp.callback_query.register(handlers["callback_alerts"], F.data.startswith("alerts:"))
    dp.callback_query.register(handlers["rearm"], F.data.startswith("rearm:"))
    dp.callback_query.register(handlers["remove"], F.data.startswith("remove:"))
    dp.message.register(handlers["target"], WatchSetup.entering_target)
    dp.message.register(handlers["text_search"], F.text)
    log.info("Bot @%s starting", settings.bot_username)
    await dp.start_polling(bot)


if __name__ == "__main__":
    asyncio.run(main())
