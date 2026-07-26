from __future__ import annotations

from io import BytesIO
from math import ceil

from PIL import Image, ImageDraw, ImageFilter, ImageFont, ImageOps

from ui import price

WIDTH, HEIGHT = 1280, 720
COVER_WIDTH = 470
PALETTE = {
    "paper": "#121925",
    "panel": "#182233",
    "panel_border": "#2b3a52",
    "ink": "#f2ebe0",
    "muted": "#a9b3c5",
    "signal": "#ff6b4a",
    "amber": "#f59e0b",
    "steam": "#1b9fff",
    "plati": "#9a78df",
    "ggsel": "#df7b3e",
}


def _font(size: int, bold: bool = False) -> ImageFont.FreeTypeFont | ImageFont.ImageFont:
    names = ["DejaVuSans-Bold.ttf" if bold else "DejaVuSans.ttf", "arialbd.ttf" if bold else "arial.ttf"]
    for name in names:
        try:
            return ImageFont.truetype(name, size)
        except OSError:
            continue
    return ImageFont.load_default()


def _fit(text: str, font: ImageFont.ImageFont, max_width: int) -> str:
    draw = ImageDraw.Draw(Image.new("RGB", (1, 1)))
    if draw.textlength(text, font=font) <= max_width:
        return text
    while text and draw.textlength(text + "…", font=font) > max_width:
        text = text[:-1]
    return text + "…"


def _cover(image_bytes: bytes | None) -> Image.Image:
    """Keep the entire game cover visible, even for unusual aspect ratios."""
    panel = Image.new("RGB", (COVER_WIDTH, HEIGHT), "#27344b")
    if not image_bytes:
        return panel
    try:
        image = Image.open(BytesIO(image_bytes)).convert("RGB")
    except Exception:
        return panel

    background = ImageOps.fit(image, panel.size, method=Image.Resampling.LANCZOS).filter(ImageFilter.GaussianBlur(18))
    panel.paste(background)
    fitted = ImageOps.contain(image, panel.size, method=Image.Resampling.LANCZOS)
    offset = ((COVER_WIDTH - fitted.width) // 2, (HEIGHT - fitted.height) // 2)
    panel.paste(fitted, offset)
    return panel


def _groups(card: dict, source: str) -> list[dict]:
    if source == "steam":
        steam = card.get("steam") or {}
        return [{"label": "Официально", "min_price": steam.get("price_rub"), "count": 1}]
    return list((card.get(source) or {}).get("by_kind") or [])


def _kind_label(group: dict) -> str:
    labels = {
        "official": "Официально",
        "key": "Ключ",
        "gift": "Гифт",
        "account": "Аккаунт",
        "rent": "Аренда",
    }
    return str(group.get("label") or labels.get(group.get("kind"), group.get("kind") or "Предложение"))


def _source_status(card: dict, source: str, groups: list[dict]) -> str:
    if groups and any(group.get("min_price") is not None for group in groups):
        return ""
    market = card.get(source) or {}
    if market.get("error"):
        return "временно недоступен"
    if card.get("refreshing"):
        return "обновляется"
    if source != "steam" and ((card.get("steam") or {}).get("note")):
        return "ждём релиз"
    return "нет цен"


def _draw_source(
    draw: ImageDraw.ImageDraw,
    card: dict,
    source: str,
    label: str,
    color: str,
    y: int,
    body_font: ImageFont.ImageFont,
    small_font: ImageFont.ImageFont,
    price_font: ImageFont.ImageFont,
) -> int:
    groups = _groups(card, source)
    status = _source_status(card, source, groups)
    draw.text((520, y), label, font=body_font, fill=PALETTE["ink"])
    draw.rounded_rectangle((505, y + 3, 511, y + 29), radius=3, fill=color)
    if status:
        draw.text((650, y + 4), status.upper(), font=small_font, fill=PALETTE["muted"])
        return y + 46

    columns = 1 if source == "steam" else 2
    chip_width = 720 if columns == 1 else 350
    rows = ceil(len(groups) / columns)
    for index, group in enumerate(groups):
        row, column = divmod(index, columns)
        x = 520 + column * (chip_width + 16)
        chip_y = y + 38 + row * 62
        draw.rounded_rectangle((x, chip_y, x + chip_width, chip_y + 52), radius=12, fill=PALETTE["panel"], outline=PALETTE["panel_border"])
        draw.text((x + 16, chip_y + 8), _fit(_kind_label(group).upper(), small_font, chip_width - 130), font=small_font, fill=PALETTE["muted"])
        count = group.get("count") or group.get("offer_count")
        count_text = f" · {count} шт." if count and source != "steam" else ""
        draw.text((x + 16, chip_y + 27), price(group.get("min_price")) + count_text, font=price_font, fill=PALETTE["ink"])
    return y + 38 + rows * 62 + 12


def render_card(card: dict, cover_bytes: bytes | None = None) -> bytes:
    result = Image.new("RGB", (WIDTH, HEIGHT), PALETTE["paper"])
    result.paste(_cover(cover_bytes), (0, 0))
    shade = Image.new("RGBA", (WIDTH, HEIGHT), (8, 14, 22, 0))
    draw_shade = ImageDraw.Draw(shade)
    draw_shade.rectangle((0, 0, COVER_WIDTH, HEIGHT), fill=(8, 14, 22, 72))
    draw_shade.polygon([(COVER_WIDTH - 84, 0), (COVER_WIDTH + 36, 0), (COVER_WIDTH - 118, HEIGHT), (COVER_WIDTH - 238, HEIGHT)], fill=(18, 25, 37, 230))
    result = Image.alpha_composite(result.convert("RGBA"), shade).convert("RGB")
    draw = ImageDraw.Draw(result)
    title_font, price_font, body_font, small_font = _font(42, True), _font(22, True), _font(25, True), _font(16, True)
    steam = card.get("steam") or {}
    name = _fit(str(steam.get("name") or "Игра"), title_font, 710)
    draw.rounded_rectangle((520, 34, 738, 70), radius=18, fill=PALETTE["signal"])
    draw.text((539, 43), "ИГРОСКАН · ЦЕНЫ", font=small_font, fill="white")
    draw.text((520, 92), name, font=title_font, fill=PALETTE["ink"])
    status = "ЕЩЁ НЕ ВЫШЛА" if steam.get("note") else "АКТУАЛЬНЫЕ ПРЕДЛОЖЕНИЯ"
    draw.text((522, 151), status, font=small_font, fill=PALETTE["amber"])

    y = 195
    y = _draw_source(draw, card, "steam", "STEAM", PALETTE["steam"], y, body_font, small_font, price_font)
    y = _draw_source(draw, card, "plati", "PLATI.MARKET", PALETTE["plati"], y, body_font, small_font, price_font)
    y = _draw_source(draw, card, "ggsel", "GGSEL", PALETTE["ggsel"], y, body_font, small_font, price_font)

    freshness = "обновление в фоне" if card.get("refreshing") else "цены из серверного хранилища"
    draw.text((520, 674), f"● {freshness}", font=small_font, fill=PALETTE["muted"])
    stream = BytesIO()
    result.save(stream, format="PNG", optimize=True)
    return stream.getvalue()
