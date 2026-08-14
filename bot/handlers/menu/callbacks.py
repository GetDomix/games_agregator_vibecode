from aiogram import F, Router
from aiogram.types import CallbackQuery

from decorators.session import session
from keyboards.menu import main_menu_keyboard

router = Router()


@router.callback_query(F.data == "menu_help")
@session
async def help_menu(callback: CallbackQuery):
    await callback.answer()
    text = '''
<b>Как пользоваться</b>

Выбери действие в меню.
'''
    await callback.message.edit_text(text, reply_markup=main_menu_keyboard())


@router.callback_query(F.data == "menu_home")
@session
async def home_menu(callback: CallbackQuery):
    await callback.answer()
    text = '''
👋 <b>Игроскан</b>

Выбери действие в меню.
'''
    await callback.message.edit_text(text, reply_markup=main_menu_keyboard())
