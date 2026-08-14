from aiogram import F, Router
from aiogram.fsm.context import FSMContext
from aiogram.types import CallbackQuery

from decorators.session import session
from keyboards.menu import main_menu_keyboard
from misc import api
from utils.card import show_card

router = Router()


@router.callback_query(F.data == "menu_search")
@session
async def search_menu(callback: CallbackQuery, state: FSMContext):
    await callback.answer()
    await state.clear()
    text = '''
Напиши название игры.
Например: <i>Hades</i>
'''
    await callback.message.edit_text(text, reply_markup=main_menu_keyboard())


@router.callback_query(F.data.startswith("pick:"))
@session
async def pick_game(callback: CallbackQuery):
    await callback.answer()
    await show_card(callback.message, int(callback.data.split(":")[1]))


@router.callback_query(F.data.startswith("card:"))
@session
async def refresh_card(callback: CallbackQuery):
    await callback.answer("Собираю сохранённые цены…")
    await show_card(callback.message, int(callback.data.split(":")[1]))
