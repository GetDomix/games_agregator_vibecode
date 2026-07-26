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

    def test_keeps_tall_cover_in_the_top_half_and_all_market_kinds_visible(self):
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
        pixel = image.getpixel((WIDTH // 2, 150))
        self.assertGreater(pixel[0], 120)
        self.assertGreater(pixel[2], 90)

    def test_keeps_wide_cover_inside_the_top_half(self):
        cover = Image.new("RGB", (960, 180), "#00c8d7")
        stream = BytesIO()
        cover.save(stream, format="PNG")
        png = render_card({"steam": {"name": "Wide game", "price_rub": 1999}, "plati": {"by_kind": []}, "ggsel": {"by_kind": []}}, stream.getvalue())
        image = Image.open(BytesIO(png)).convert("RGB")
        pixel = image.getpixel((WIDTH // 2, 150))
        self.assertGreater(pixel[1], 130)
        self.assertGreater(pixel[2], 130)
