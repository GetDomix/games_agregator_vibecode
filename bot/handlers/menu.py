from aiogram import F, Router
from aiogram.types import Message

from keyboards import MENU_HELP, MENU_HOME, main_menu_keyboard
from misc import api
from utils.telegram import session


router = Router()


@router.message(F.text == MENU_HELP)
async def help_menu(message: Message):
    await message.answer("<b>Как пользоваться</b>\n\nВыбери «Найти игру» и напиши название. На карточке можно добавить игру в общее избранное, выбрать виды предложений и задать цену.\n\n«Избранное» и «Алерты» показывают те же данные, что и сайт.", reply_markup=main_menu_keyboard())


@router.message(F.text == MENU_HOME)
async def home_menu(message: Message):
    if await session(api, message):
        await message.answer("👋 <b>Игроскан</b>\n\nВыбери действие кнопкой или напиши название игры — я покажу сохранённую карточку цен.", reply_markup=main_menu_keyboard())
