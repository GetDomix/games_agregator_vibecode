from aiogram.fsm.context import FSMContext
from aiogram.types import Message

from api import LaravelClient
from keyboards import main_menu_keyboard
from utils.telegram import session


async def show_main_menu(message: Message) -> None:
    await message.answer("👋 <b>Игроскан</b>\n\nВыбери действие кнопкой или напиши название игры — я покажу сохранённую карточку цен.", parse_mode="HTML", reply_markup=main_menu_keyboard())


def handlers(api: LaravelClient, search_command):
    async def menu_search(message: Message, state: FSMContext) -> None:
        await search_command(message, state)

    async def help_command(message: Message) -> None:
        await message.answer("<b>Как пользоваться</b>\n\nВыбери «Найти игру» и напиши название. На карточке можно добавить игру в общее избранное, выбрать виды предложений и задать цену.\n\n«Избранное» и «Алерты» показывают те же данные, что и сайт.", parse_mode="HTML", reply_markup=main_menu_keyboard())

    async def home(message: Message) -> None:
        if await session(api, message):
            await show_main_menu(message)

    return {"menu_search": menu_search, "menu_help": help_command, "menu_home": home}
