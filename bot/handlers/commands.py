import re

from aiogram.filters import CommandObject
from aiogram.fsm.context import FSMContext
from aiogram.types import Message

from api import LaravelApiError, LaravelClient
from keyboards import main_menu_keyboard
from utils.telegram import private, session

CODE_RE = re.compile(r"^[A-Za-z0-9]{6,16}$")


def handlers(api: LaravelClient, show_main_menu):
    async def start(message: Message, command: CommandObject) -> None:
        if not private(message):
            await message.answer("Открой бота в личном чате, чтобы привязать аккаунт.")
            return
        code = (command.args or "").strip().upper()
        if code:
            if not CODE_RE.match(code):
                await message.answer("Код выглядит странно. Скопируй его с сайта целиком.")
                return
            try:
                data = await api.bind_telegram(code, message.from_user.id, message.chat.id, message.from_user.username)
                await api.session(message.from_user.id, message.chat.id, message.from_user.username, message.from_user.full_name)
                await message.answer(f"✅ Привязка готова, <b>{data.get('display_name') or 'игрок'}</b>. Данные сайта и бота теперь общие.", parse_mode="HTML")
            except LaravelApiError as exc:
                await message.answer(f"Не вышло привязать: {exc}")
                return
        elif not await session(api, message):
            return
        await show_main_menu(message)

    async def help_command(message: Message) -> None:
        await message.answer("<b>Как пользоваться</b>\n\nВыбери «Найти игру» и напиши название. На карточке можно добавить игру в общее избранное, выбрать виды предложений и задать цену.\n\n«Избранное» и «Алерты» показывают те же данные, что и сайт.", parse_mode="HTML", reply_markup=main_menu_keyboard())

    async def search_command(message: Message, state: FSMContext) -> None:
        if await session(api, message):
            await state.clear()
            await message.answer("Напиши название игры. Например: <i>Hades</i>", parse_mode="HTML")

    return {"cmd_start": start, "cmd_help": help_command, "cmd_search": search_command}
