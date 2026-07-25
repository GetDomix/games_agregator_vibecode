import unittest

from card_renderer import HEIGHT, WIDTH, render_card
from PIL import Image


class CardRendererTest(unittest.TestCase):
    def test_renders_site_style_png_without_cover(self):
        png = render_card({"steam": {"name": "Soon", "price_rub": None, "note": "coming soon"}, "plati": {"by_kind": []}, "ggsel": {"by_kind": []}, "refreshing": True})
        image = Image.open(__import__("io").BytesIO(png))
        self.assertEqual(image.size, (WIDTH, HEIGHT))
        self.assertEqual(image.format, "PNG")
