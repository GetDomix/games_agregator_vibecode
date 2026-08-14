from aiogram.enums import ChatAction
from aiogram.fsm.context import FSMContext
from aiogram.types import CallbackQuery, Message

from api import LaravelApiError, LaravelClient
from keyboards import candidates_keyboard
from utils.telegram import actor, private, session


def handlers(api: LaravelClient, show_card):
    async def text_search(message: Message, state: FSMContext) -> None:
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

    async def pick(callback: CallbackQuery, state: FSMContext) -> None:
        await callback.answer()
        data = await state.get_data()
        await show_card(api, callback.message, actor(callback), int(callback.data.split(":")[1]), data.get("query"))

    async def refresh(callback: CallbackQuery, state: FSMContext) -> None:
        await callback.answer("Собираю сохранённые цены…")
        data = await state.get_data()
        await show_card(api, callback.message, actor(callback), int(callback.data.split(":")[1]), data.get("query"))

    return {"text_search": text_search, "pick": pick, "refresh_card": refresh}
