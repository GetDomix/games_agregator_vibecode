from aiogram.enums import ChatType
from aiogram.types import CallbackQuery, Message

from api.laravel import LaravelApiError, LaravelClient


def message_of(update: Message | CallbackQuery) -> Message:
    return update.message if isinstance(update, CallbackQuery) else update


def private(update: Message | CallbackQuery) -> bool:
    return message_of(update).chat.type == ChatType.PRIVATE and update.from_user is not None


def actor(update: Message | CallbackQuery) -> int:
    return update.from_user.id


async def session(api: LaravelClient, update: Message | CallbackQuery) -> bool:
    message = message_of(update)
    if not private(update):
        await message.answer("Для безопасности открой Игроскан в личном чате с ботом.")
        return False
    try:
        await api.session(update.from_user.id, message.chat.id, update.from_user.username, update.from_user.full_name)
        return True
    except LaravelApiError as exc:
        await message.answer(str(exc))
        return False
