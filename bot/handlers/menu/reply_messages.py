from aiogram import F, Router
from aiogram.types import Message

from keyboards.menu import main_menu_keyboard
from misc import api
from utils.telegram import session

router = Router()


@router.message(F.text == "❔ Помощь")
async def help_menu(message: Message):
    await message.answer("<b>Как пользоваться</b>\n\nВыбери «Найти игру» и напиши название.", reply_markup=main_menu_keyboard())


@router.message(F.text == "🏠 Главное меню")
async def home_menu(message: Message):
    if await session(api, message):
        await message.answer("👋 <b>Игроскан</b>\n\nВыбери действие кнопкой или напиши название игры.", reply_markup=main_menu_keyboard())
