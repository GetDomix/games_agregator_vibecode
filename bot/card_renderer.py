from __future__ import annotations

from io import BytesIO
from math import ceil
from pathlib import Path

from PIL import Image, ImageDraw, ImageEnhance, ImageFilter, ImageFont, ImageOps

from ui import price

WIDTH, HEIGHT = 1280, 720
COVER_HEIGHT = 330
ASSETS = Path(__file__).with_name("assets")
PALETTE = {
    "paper": "#101722",
    "panel": "#172131",
    "panel_deep": "#111a28",
    "panel_border": "#2b3a52",
    "ink": "#f4efe7",
    "muted": "#9da9bd",
    "signal": "#ff6b4a",
    "cool": "#61b8ff",
    "violet": "#aa8cff",
    "good": "#62d86b",
    "okay": "#f5bd45",
    "bad": "#ff6259",
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
    """Keep the whole cover visible and add a restrained illustrated texture."""
    canvas = Image.new("RGB", (WIDTH, COVER_HEIGHT), "#202c3b")
    if not image_bytes:
        return canvas
    try:
        image = Image.open(BytesIO(image_bytes)).convert("RGB")
    except Exception:
        return canvas

    background = ImageOps.fit(image, canvas.size, method=Image.Resampling.LANCZOS)
    background = ImageEnhance.Color(background).enhance(0.65).filter(ImageFilter.GaussianBlur(20))
    canvas.paste(background)

    illustrated = ImageOps.posterize(image, 5)
    illustrated = ImageEnhance.Contrast(illustrated).enhance(1.15)
    styled = Image.blend(image, illustrated, 0.28)
    fitted = ImageOps.contain(styled, canvas.size, method=Image.Resampling.LANCZOS)
    offset = ((WIDTH - fitted.width) // 2, (COVER_HEIGHT - fitted.height) // 2)
    canvas.paste(fitted, offset)

    texture = Image.new("RGBA", canvas.size, (0, 0, 0, 0))
    texture_draw = ImageDraw.Draw(texture)
    for x in range(18, WIDTH, 28):
        for y in range(16, COVER_HEIGHT, 28):
            texture_draw.ellipse((x, y, x + 2, y + 2), fill=(244, 239, 231, 28))
    texture_draw.rectangle((0, 0, WIDTH - 1, COVER_HEIGHT - 1), outline=(244, 239, 231, 70), width=2)
    return Image.alpha_composite(canvas.convert("RGBA"), texture).convert("RGB")


def _groups(card: dict, source: str) -> list[dict]:
    if source == "steam":
        steam = card.get("steam") or {}
        return [{"label": "Официально", "min_price": steam.get("price_rub"), "count": 1}]
    return list((card.get(source) or {}).get("by_kind") or [])


def _kind_label(group: dict) -> str:
    labels = {"official": "Официально", "key": "Ключ", "gift": "Гифт", "account": "Аккаунт", "rent": "Аренда"}
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


def _price_tone(value: object | None, steam_price: object | None) -> str:
    if value is None or steam_price is None:
        return PALETTE["muted"]
    ratio = float(value) / float(steam_price)
    if ratio >= 1:
        return PALETTE["bad"]
    if ratio >= 0.7:
        return PALETTE["okay"]
    return PALETTE["good"]


def _logo(source: str, size: int) -> Image.Image:
    if source == "steam":
        icon = Image.new("RGBA", (size, size), "#1b75bb")
        draw = ImageDraw.Draw(icon)
        draw.ellipse((0, 0, size - 1, size - 1), fill="#1b75bb")
        line = max(2, size // 13)
        draw.line((size * 0.18, size * 0.67, size * 0.64, size * 0.44), fill="white", width=line)
        draw.ellipse((size * 0.10, size * 0.55, size * 0.35, size * 0.80), outline="white", width=line)
        draw.ellipse((size * 0.59, size * 0.24, size * 0.92, size * 0.57), outline="white", width=line)
        draw.ellipse((size * 0.70, size * 0.35, size * 0.81, size * 0.46), fill="white")
        return icon
    filename = "plati-market-logo.png" if source == "plati" else "ggsel-logo.png"
    logo = Image.open(ASSETS / filename).convert("RGBA")
    return ImageOps.contain(logo, (size, size), method=Image.Resampling.LANCZOS)


def _draw_market(
    result: Image.Image,
    draw: ImageDraw.ImageDraw,
    card: dict,
    source: str,
    label: str,
    box: tuple[int, int, int, int],
    price_font: ImageFont.ImageFont,
    kind_font: ImageFont.ImageFont,
    meta_font: ImageFont.ImageFont,
) -> None:
    x1, y1, x2, y2 = box
    groups = _groups(card, source)
    status = _source_status(card, source, groups)
    draw.rounded_rectangle(box, radius=14, fill=PALETTE["panel"], outline=PALETTE["panel_border"], width=2)
    logo = _logo(source, 48)
    result.paste(logo, (x1 + 18, y1 + 8), logo)
    draw.text((x1 + 42, y2 - 12), _fit(label, _font(9, True), 62), font=_font(9, True), fill=PALETTE["muted"], anchor="ms")
    if status:
        draw.text((x1 + 88, y1 + 18), status.upper(), font=kind_font, fill=PALETTE["muted"])
        return

    steam_price = (card.get("steam") or {}).get("price_rub")
    if source == "steam":
        draw.text((x1 + 88, y1 + 8), "ОФИЦИАЛЬНАЯ ЦЕНА", font=meta_font, fill=PALETTE["muted"])
        draw.text((x1 + 88, y1 + 24), price(steam_price), font=price_font, fill=PALETTE["ink"])
        return

    kinds_x = x1 + 88
    kind_width = x2 - kinds_x - 20
    columns = 3 if len(groups) > 4 else 2
    chip_width = kind_width if columns == 1 else (kind_width - 10) // 2
    rows = ceil(len(groups) / columns)
    chip_gap = 4
    chip_height = max(30, (y2 - y1 - 14 - chip_gap * max(rows - 1, 0)) // max(rows, 1))
    for index, group in enumerate(groups):
        row, column = divmod(index, columns)
        chip_x = kinds_x + column * (chip_width + 10)
        chip_y = y1 + 7 + row * (chip_height + chip_gap)
        label_text = _fit(_kind_label(group).upper(), meta_font, chip_width - 12)
        count = group.get("count") or group.get("offer_count")
        suffix = f" · {count}" if count and source != "steam" else ""
        draw.rounded_rectangle((chip_x, chip_y, chip_x + chip_width, chip_y + chip_height), radius=8, fill=PALETTE["panel_deep"])
        amount = group.get("min_price")
        draw.text((chip_x + 10, chip_y + 4), label_text, font=meta_font, fill=PALETTE["muted"])
        draw.text((chip_x + 10, chip_y + 17), _fit(price(amount), price_font, chip_width - 14), font=price_font, fill=_price_tone(amount, steam_price))
        if suffix:
            draw.text((chip_x + chip_width - 9, chip_y + chip_height - 5), suffix.strip(" ·"), font=meta_font, fill=PALETTE["muted"], anchor="rs")


def render_card(card: dict, cover_bytes: bytes | None = None) -> bytes:
    result = Image.new("RGB", (WIDTH, HEIGHT), PALETTE["paper"])
    result.paste(_cover(cover_bytes), (0, 0))
    draw = ImageDraw.Draw(result)
    draw.rounded_rectangle((0, COVER_HEIGHT - 24, WIDTH, HEIGHT), radius=28, fill=PALETTE["paper"])
    draw.rectangle((0, COVER_HEIGHT, WIDTH, HEIGHT), fill=PALETTE["paper"])
    draw.rectangle((0, COVER_HEIGHT, WIDTH, COVER_HEIGHT + 4), fill=PALETTE["signal"])

    title_font, price_font = _font(30, True), _font(20, True)
    kind_font, meta_font = _font(14, True), _font(11, True)
    steam = card.get("steam") or {}
    name = _fit(str(steam.get("name") or "Игра"), title_font, WIDTH - 360)
    draw.text((36, COVER_HEIGHT + 28), name, font=title_font, fill=PALETTE["ink"])
    status = "ЕЩЁ НЕ ВЫШЛА" if steam.get("note") else "ЦЕНЫ ИЗ СЕРВЕРНОГО ХРАНИЛИЩА"
    draw.text((38, COVER_HEIGHT + 69), status, font=meta_font, fill=PALETTE["muted"])
    draw.rounded_rectangle((WIDTH - 204, COVER_HEIGHT + 28, WIDTH - 36, COVER_HEIGHT + 60), radius=16, fill=PALETTE["signal"])
    draw.text((WIDTH - 184, COVER_HEIGHT + 37), "ИГРОСКАН", font=kind_font, fill="white")

    top = COVER_HEIGHT + 94
    steam_box = (36, top, WIDTH - 36, top + 50)
    plati_box = (36, top + 56, WIDTH - 36, top + 160)
    ggsel_box = (36, top + 166, WIDTH - 36, HEIGHT - 18)
    _draw_market(result, draw, card, "steam", "Steam", steam_box, price_font, kind_font, meta_font)
    _draw_market(result, draw, card, "plati", "Plati.Market", plati_box, price_font, kind_font, meta_font)
    _draw_market(result, draw, card, "ggsel", "GGSEL", ggsel_box, price_font, kind_font, meta_font)

    stream = BytesIO()
    result.save(stream, format="PNG", optimize=True)
    return stream.getvalue()
