from aiogram.enums import ChatAction
from aiogram.types import BufferedInputFile, Message

from api import LaravelApiError, LaravelClient
from services import render_card
from keyboards import card_keyboard
from ui import format_card_details
from utils.telegram import actor


async def show_card(api: LaravelClient, message: Message, telegram_user_id: int, appid: int, query: str | None = None) -> None:
    await message.bot.send_chat_action(message.chat.id, ChatAction.TYPING)
    try:
        payload = await api.card(telegram_user_id, appid, query)
    except LaravelApiError as exc:
        await message.answer(str(exc))
        return
    card, favorite = payload["card"], payload.get("favorite")
    await message.bot.send_chat_action(message.chat.id, ChatAction.UPLOAD_PHOTO)
    cover = await api.image((card.get("steam") or {}).get("header_image"))
    await message.answer_photo(BufferedInputFile(render_card(card, cover), filename=f"igroscan-{appid}.png"), caption=format_card_details(card, favorite), parse_mode="HTML", reply_markup=card_keyboard(appid, favorite is not None))
