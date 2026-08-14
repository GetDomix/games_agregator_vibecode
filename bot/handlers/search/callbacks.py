from aiogram import F, Router
from aiogram.fsm.context import FSMContext
from aiogram.types import CallbackQuery

from keyboards.menu import main_menu_keyboard
from misc import api
from utils.card import show_card
from utils.telegram import session

router = Router()


@router.callback_query(F.data == "menu_search")
async def search_menu(callback: CallbackQuery, state: FSMContext):
    await callback.answer()
    if await session(api, callback):
        await state.clear()
        text = '''
Напиши название игры.
Например: <i>Hades</i>
'''
        await callback.message.answer(text, reply_markup=main_menu_keyboard())


@router.callback_query(F.data.startswith("pick:"))
async def pick_game(callback: CallbackQuery):
    await callback.answer()
    await show_card(callback.message, int(callback.data.split(":")[1]))


@router.callback_query(F.data.startswith("card:"))
async def refresh_card(callback: CallbackQuery):
    await callback.answer("Собираю сохранённые цены…")
    await show_card(callback.message, int(callback.data.split(":")[1]))
