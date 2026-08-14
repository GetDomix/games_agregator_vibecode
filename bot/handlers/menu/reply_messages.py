from aiogram import F, Router
from aiogram.types import Message

from keyboards.menu import main_menu_keyboard
from misc import api

from utils.telegram import session

router = Router()


@router.message(F.text == "❔ Помощь")
async def help_menu(message: Message):
    text = '''
<b>Как пользоваться</b>

Выбери «Найти игру» и напиши название.
'''

    await message.answer(text, reply_markup=main_menu_keyboard())


@router.message(F.text == "🏠 Главное меню")
async def home_menu(message: Message):
    if await session(api, message):
        text = '''
👋 <b>Игроскан</b>

Выбери действие кнопкой или напиши название игры.
'''
        await message.answer(text, reply_markup=main_menu_keyboard())
