import re

from aiogram import Router
from aiogram.filters import Command, CommandObject, CommandStart
from aiogram.fsm.context import FSMContext
from aiogram.types import Message

from api import LaravelApiError
from keyboards.menu import main_menu_keyboard
from misc import api
from utils.telegram import private, session

router = Router()


@router.message(CommandStart())
async def start(message: Message, command: CommandObject):
    if not private(message):
        await message.answer("Открой бота в личном чате, чтобы привязать аккаунт.")
        return
    code = (command.args or "").strip().upper()
    if code:
        if not re.fullmatch(r"[A-Za-z0-9]{6,16}", code):
            await message.answer("Код выглядит странно. Скопируй его с сайта целиком.")
            return
        try:
            data = await api.bind_telegram(code, message.from_user.id, message.chat.id, message.from_user.username)
            await api.session(message.from_user.id, message.chat.id, message.from_user.username, message.from_user.full_name)
            await message.answer(f"✅ Привязка готова, <b>{data.get('display_name') or 'игрок'}</b>. Данные сайта и бота теперь общие.")
        except LaravelApiError as exc:
            await message.answer(f"Не вышло привязать: {exc}")
            return
    elif not await session(api, message):
        return
    await message.answer("👋 <b>Игроскан</b>\n\nВыбери действие кнопкой или напиши название игры.", reply_markup=main_menu_keyboard())


@router.message(Command("help"))
async def help_command(message: Message):
    await message.answer("<b>Как пользоваться</b>\n\nВыбери «Найти игру» и напиши название.", reply_markup=main_menu_keyboard())


@router.message(Command("search"))
async def search_command(message: Message, state: FSMContext):
    if await session(api, message):
        await state.clear()
        await message.answer("Напиши название игры. Например: <i>Hades</i>")
