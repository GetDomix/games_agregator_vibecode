import unittest

from ui import (MENU_ALERTS, MENU_FAVORITES, MENU_SEARCH, candidates_keyboard,
                format_card_details, main_menu_keyboard, official_price, price)


class UiTest(unittest.TestCase):
    def test_main_menu_keeps_primary_actions_on_reply_keyboard(self):
        keyboard = main_menu_keyboard()

        self.assertEqual(keyboard.keyboard[0][0].text, MENU_SEARCH)
        self.assertEqual([button.text for button in keyboard.keyboard[1]], [MENU_FAVORITES, MENU_ALERTS])
        self.assertTrue(keyboard.resize_keyboard)

    def test_price_and_html_are_safe(self):
        self.assertEqual(price(1234), "1 234 ₽")
        text = format_card_details({"steam": {"name": "<script>", "price_rub": 99}}, None)
        self.assertIn("&lt;script&gt;", text)
        self.assertNotIn("<b>Steam:</b>", text)

    def test_candidate_callback_is_compact(self):
        keyboard = candidates_keyboard([{"appid": 123, "name": "Game"}])
        self.assertEqual(keyboard.inline_keyboard[0][0].callback_data, "pick:123")

    def test_official_regional_price_keeps_its_currency_and_ruble_estimate(self):
        steam = {"regional_prices": [{"region": "US", "currency": "USD", "amount": 59.99, "price_rub": 4799.2}]}
        self.assertEqual(official_price(steam), "$59.99 (≈ 4 799 ₽)")
