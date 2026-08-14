import unittest
from types import SimpleNamespace
from unittest.mock import AsyncMock, MagicMock, patch

from aiogram.enums import ChatType
from aiogram.types import CallbackQuery, Message

from api_client import LaravelApiError
from compat import make_handlers, show_card
from states.watch_setup import WatchSetup
from utils.telegram import session
from ui import MENU_FAVORITES, MENU_HOME, MENU_SEARCH


def make_message(*, text: str = "", chat_type: ChatType = ChatType.PRIVATE) -> Message:
    message = MagicMock(spec=Message)
    message.chat = SimpleNamespace(id=12, type=chat_type)
    message.from_user = SimpleNamespace(id=12, username="player", full_name="Test Player")
    message.text = text
    message.answer = AsyncMock()
    message.edit_text = AsyncMock()
    message.answer_photo = AsyncMock()
    message.bot = SimpleNamespace(send_chat_action=AsyncMock())
    return message


def make_callback(message: Message, data: str) -> CallbackQuery:
    callback = MagicMock(spec=CallbackQuery)
    callback.message = message
    callback.from_user = message.from_user
    callback.data = data
    callback.answer = AsyncMock()
    return callback


def make_state(**data):
    state = MagicMock()
    state.get_data = AsyncMock(return_value=data)
    state.set_data = AsyncMock()
    state.update_data = AsyncMock()
    state.set_state = AsyncMock()
    state.clear = AsyncMock()
    return state


class HandlerTest(unittest.IsolatedAsyncioTestCase):
    async def test_start_shows_main_menu_instead_of_command_list(self):
        api = MagicMock()
        api.session = AsyncMock(return_value={})
        message = make_message()

        await make_handlers(api)["cmd_start"](message, SimpleNamespace(args=None))

        self.assertEqual(message.answer.await_args.kwargs["reply_markup"].inline_keyboard[0][0].text, MENU_SEARCH)
        self.assertNotIn("/search", message.answer.await_args.args[0])

    async def test_menu_search_reuses_the_normal_search_flow(self):
        api = MagicMock()
        api.session = AsyncMock(return_value={})
        message = make_message(text=MENU_SEARCH)
        state = make_state()

        await make_handlers(api)["menu_search"](message, state)

        state.clear.assert_awaited_once_with()
        self.assertIn("Напиши название игры", message.edit_text.await_args.args[0])

    async def test_menu_favorites_opens_shared_favorites(self):
        api = MagicMock()
        api.session = AsyncMock(return_value={})
        api.favorites = AsyncMock(return_value={"items": []})
        message = make_message(text=MENU_FAVORITES)

        await make_handlers(api)["menu_favorites"](message)

        api.favorites.assert_awaited_once_with(12)
        self.assertIn("В избранном пока пусто", message.edit_text.await_args.args[0])

    async def test_menu_home_keeps_reply_menu_available(self):
        api = MagicMock()
        api.session = AsyncMock(return_value={})
        message = make_message(text=MENU_HOME)

        await make_handlers(api)["menu_home"](message)

        self.assertEqual(message.edit_text.await_args.kwargs["reply_markup"].inline_keyboard[0][0].text, MENU_SEARCH)

    async def test_show_card_sends_one_photo_with_caption_and_actions(self):
        api = MagicMock()
        api.card = AsyncMock(return_value={
            "card": {"steam": {"name": "Half-Life", "header_image": "https://img.test/70.jpg", "price_rub": 99}, "plati": {"by_kind": []}, "ggsel": {"by_kind": []}},
            "favorite": None,
        })
        api.image = AsyncMock(return_value=None)
        message = make_message()

        await show_card(api, message, 12, 70)

        message.answer_photo.assert_awaited_once()
        self.assertIn("Цены и типы предложений", message.answer_photo.await_args.kwargs["caption"])
        self.assertIsNotNone(message.answer_photo.await_args.kwargs["reply_markup"])
        message.answer.assert_not_awaited()

    async def test_session_rejects_group_chat_without_api_call(self):
        api = MagicMock()
        api.session = AsyncMock()
        message = make_message(chat_type=ChatType.GROUP)

        self.assertFalse(await session(api, message))

        api.session.assert_not_awaited()
        message.answer.assert_awaited_once_with("Для безопасности открой Игроскан в личном чате с ботом.")

    async def test_start_rejects_invalid_link_code(self):
        api = MagicMock()
        api.bind_telegram = AsyncMock()
        message = make_message()
        handlers = make_handlers(api)

        await handlers["cmd_start"](message, SimpleNamespace(args="bad!"))

        api.bind_telegram.assert_not_awaited()
        message.answer.assert_awaited_once_with("Код выглядит странно. Скопируй его с сайта целиком.")

    async def test_text_search_stores_query_and_offers_candidates(self):
        api = MagicMock()
        api.session = AsyncMock(return_value={})
        api.search = AsyncMock(return_value={"candidates": [{"appid": 70, "name": "Half-Life"}]})
        message = make_message(text="  Half-Life  ")
        state = make_state()

        await make_handlers(api)["text_search"](message, state)

        api.search.assert_awaited_once_with(12, "Half-Life")
        state.update_data.assert_awaited_once_with(query="Half-Life")
        self.assertEqual(message.answer.await_args.args[0], "Выбери игру:")
        self.assertEqual(message.answer.await_args.kwargs["reply_markup"].inline_keyboard[0][0].callback_data, "pick:70")

    async def test_begin_watch_loads_existing_scopes_into_fsm(self):
        api = MagicMock()
        api.card = AsyncMock(return_value={
            "card": {"steam": {"name": "Half-Life", "header_image": "https://img.test/70.jpg"}},
            "favorite": {"alert": {"scopes": [{"source": "ggsel", "offer_kind": "key"}]}},
        })
        message = make_message()
        callback = make_callback(message, "watch:70")
        state = make_state()

        await make_handlers(api)["begin_watch"](callback, state)

        state.set_state.assert_awaited_once_with(WatchSetup.choosing_scopes)
        saved = state.set_data.await_args.kwargs
        self.assertEqual(saved["game"]["appid"], 70)
        self.assertCountEqual(saved["scopes"], [["steam", "official"], ["ggsel", "key"]])

    async def test_target_rejects_invalid_price_without_saving(self):
        api = MagicMock()
        api.save_favorite = AsyncMock()
        message = make_message(text="-10")
        state = make_state(game={"appid": 70}, scopes=[["steam", "official"]])

        await make_handlers(api)["target"](message, state)

        api.save_favorite.assert_not_awaited()
        state.clear.assert_not_awaited()
        self.assertIn("Введи цену числом", message.answer.await_args.args[0])

    async def test_target_saves_scopes_clears_fsm_and_shows_card(self):
        api = MagicMock()
        api.save_favorite = AsyncMock(return_value={"appid": 70})
        message = make_message(text="1 299,50")
        state = make_state(
            game={"appid": 70, "name": "Half-Life", "header_image": None},
            scopes=[["steam", "official"], ["plati", "key"]],
        )

        with patch("compat.show_card", new_callable=AsyncMock) as show_card:
            await make_handlers(api)["target"](message, state)

        api.save_favorite.assert_awaited_once_with(
            12,
            {"appid": 70, "name": "Half-Life", "header_image": None},
            1299.5,
            [
                {"source": "steam", "offer_kind": "official"},
                {"source": "plati", "offer_kind": "key"},
            ],
        )
        state.clear.assert_awaited_once_with()
        show_card.assert_awaited_once_with(api, message, 12, 70)

    async def test_rearm_reports_api_error_as_callback_alert(self):
        api = MagicMock()
        api.rearm = AsyncMock(side_effect=LaravelApiError("Алерт не найден"))
        callback = make_callback(make_message(), "rearm:70")

        await make_handlers(api)["rearm"](callback)

        callback.answer.assert_awaited_once_with("Алерт не найден", show_alert=True)
