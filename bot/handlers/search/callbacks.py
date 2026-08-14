from aiogram import F, Router
from aiogram.types import CallbackQuery

from utils.card import show_card

router = Router()


@router.callback_query(F.data.startswith("pick:"))
async def pick_game(callback: CallbackQuery):
    await callback.answer()
    await show_card(callback.message, int(callback.data.split(":")[1]))


@router.callback_query(F.data.startswith("card:"))
async def refresh_card(callback: CallbackQuery):
    await callback.answer("Собираю сохранённые цены…")
    await show_card(callback.message, int(callback.data.split(":")[1]))
