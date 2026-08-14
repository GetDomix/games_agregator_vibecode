from aiogram import F, Router
from aiogram.fsm.context import FSMContext
from aiogram.types import CallbackQuery

from keyboards.menu import main_menu_keyboard
from misc import api
from utils.telegram import session

router = Router()


@router.callback_query(F.data == "menu_help")
async def help_menu(callback: CallbackQuery):
    await callback.answer()
    text = '''
<b>Как пользоваться</b>

Выбери действие в меню.
'''
    await callback.message.answer(text, reply_markup=main_menu_keyboard())


@router.callback_query(F.data == "menu_home")
async def home_menu(callback: CallbackQuery):
    await callback.answer()
    if await session(api, callback):
        text = '''
👋 <b>Игроскан</b>

Выбери действие в меню.
'''
        await callback.message.answer(text, reply_markup=main_menu_keyboard())
