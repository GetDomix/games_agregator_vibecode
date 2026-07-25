import unittest

from ui import candidates_keyboard, format_card_details, price


class UiTest(unittest.TestCase):
    def test_price_and_html_are_safe(self):
        self.assertEqual(price(1234), "1 234 ₽")
        text = format_card_details({"steam": {"name": "<script>", "price_rub": 99}}, None)
        self.assertIn("&lt;script&gt;", text)

    def test_candidate_callback_is_compact(self):
        keyboard = candidates_keyboard([{"appid": 123, "name": "Game"}])
        self.assertEqual(keyboard.inline_keyboard[0][0].callback_data, "pick:123")
