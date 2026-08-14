from aiogram.enums import ChatAction
from aiogram.types import BufferedInputFile, Message

from api.laravel import LaravelApiError
from keyboards.cards import card_keyboard
from misc import api
from card_renderer import render_card
from ui import format_card_details
from utils.telegram import actor


async def show_card(message: Message, appid: int, query: str | None = None):
    await message.bot.send_chat_action(message.chat.id, ChatAction.TYPING)
    try:
        payload = await api.card(actor(message), appid, query)
    except LaravelApiError as exc:
        await message.answer(str(exc))
        return
    card, favorite = payload["card"], payload.get("favorite")
    cover = await api.image((card.get("steam") or {}).get("header_image"))
    await message.answer_photo(BufferedInputFile(render_card(card, cover), filename=f"igroscan-{appid}.png"), caption=format_card_details(card, favorite), reply_markup=card_keyboard(appid, favorite is not None))
