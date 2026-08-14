import unittest

from igroscan_bot.presentation.ui import (MENU_ALERTS, MENU_FAVORITES, MENU_SEARCH, alert_condition_label, candidates_keyboard,
                format_alerts, format_card_details, format_favorites, main_menu_keyboard, official_price, price)


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

    def test_alert_condition_labels_keep_target_and_new_modes_honest(self):
        self.assertEqual(alert_condition_label({"condition_type": "target_price", "target_value": 500}), "цель 500 ₽")
        self.assertEqual(alert_condition_label({"condition_type": "discount_percent", "target_value": 30}), "скидка Steam от 30%")
        self.assertEqual(alert_condition_label({"condition_type": "new_low"}), "новый минимум наблюдений")

    def test_card_favorites_and_alert_lists_do_not_render_percent_or_new_low_as_ruble_targets(self):
        discount = {"condition_type": "discount_percent", "target_value": 30, "status": "active"}
        new_low = {"condition_type": "new_low", "target_value": None, "status": "triggered"}

        self.assertIn("скидка Steam от 30%", format_card_details({"steam": {"name": "Game"}}, {"alert": discount}))
        favorites = format_favorites([
            {"appid": 1, "game_name": "Discount", "last_steam_price_rub": 500, "alert": discount},
            {"appid": 2, "game_name": "Low", "last_steam_price_rub": 600, "alert": new_low},
        ])
        self.assertIn("скидка Steam от 30%", favorites)
        self.assertIn("новый минимум наблюдений", favorites)
        alerts = format_alerts([
            {**discount, "favorite": {"appid": 1, "game_name": "Discount"}},
            {**new_low, "favorite": {"appid": 2, "game_name": "Low"}},
        ], "active")
        self.assertIn("скидка Steam от 30%", alerts)
        self.assertIn("новый минимум наблюдений", alerts)
        self.assertNotIn("цель 30 ₽", alerts)
        self.assertNotIn("цель —", alerts)
