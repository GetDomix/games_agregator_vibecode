from aiogram import F, Router
from aiogram.enums import ChatAction
from aiogram.fsm.context import FSMContext
from aiogram.types import CallbackQuery, Message

from api import LaravelApiError
from handlers.cards import show_card
from keyboards import candidates_keyboard, MENU_SEARCH
from misc import api
from utils.telegram import actor, private, session


router = Router()


@router.message(F.text == MENU_SEARCH)
async def search_menu(message: Message, state: FSMContext):
    if await session(api, message):
        await state.clear()
        await message.answer("Напиши название игры. Например: <i>Hades</i>")


@router.message(F.text)
async def search_game(message: Message, state: FSMContext):
    if not private(message) or not message.text or message.text.startswith("/") or not await session(api, message):
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


@router.callback_query(F.data.startswith("pick:"))
async def pick_game(callback: CallbackQuery, state: FSMContext):
    await callback.answer()
    data = await state.get_data()
    await show_card(callback.message, int(callback.data.split(":")[1]), data.get("query"))


@router.callback_query(F.data.startswith("card:"))
async def refresh_card(callback: CallbackQuery, state: FSMContext):
    await callback.answer("Собираю сохранённые цены…")
    data = await state.get_data()
    await show_card(callback.message, int(callback.data.split(":")[1]), data.get("query"))
