from functools import wraps

from aiogram.types import CallbackQuery, Message

import misc
from utils.telegram import session as ensure_session


def session(handler):
    @wraps(handler)
    async def wrapper(event: Message | CallbackQuery, *args, **kwargs):
        if await ensure_session(misc.api, event):
            return await handler(event, *args, **kwargs)

    return wrapper
