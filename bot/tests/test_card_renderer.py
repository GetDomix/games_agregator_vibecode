import unittest
from io import BytesIO

from card_renderer import HEIGHT, WIDTH, render_card
from PIL import Image


class CardRendererTest(unittest.TestCase):
    def test_renders_site_style_png_without_cover(self):
        png = render_card({"steam": {"name": "Soon", "price_rub": None, "note": "coming soon"}, "plati": {"by_kind": []}, "ggsel": {"by_kind": []}, "refreshing": True})
        image = Image.open(BytesIO(png))
        self.assertEqual(image.size, (WIDTH, HEIGHT))
        self.assertEqual(image.format, "PNG")

    def test_keeps_tall_cover_and_all_market_kinds_visible(self):
        cover = Image.new("RGB", (120, 480), "#ff00aa")
        stream = BytesIO()
        cover.save(stream, format="PNG")
        png = render_card({
            "steam": {"name": "Cyberpunk 2077", "price_rub": 1999},
            "plati": {"by_kind": [{"kind": "key", "min_price": 900, "count": 12}, {"kind": "gift", "min_price": 950, "count": 4}]},
            "ggsel": {"by_kind": [{"kind": "account", "min_price": 499, "count": 8}, {"kind": "rent", "min_price": 99, "count": 3}]},
        }, stream.getvalue())
        image = Image.open(BytesIO(png)).convert("RGB")
        self.assertEqual(image.size, (WIDTH, HEIGHT))
        pixel = image.getpixel((WIDTH // 8, HEIGHT // 2))
        self.assertGreater(pixel[0], 150)
        self.assertGreater(pixel[2], 100)
