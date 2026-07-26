from __future__ import annotations

from io import BytesIO
from math import ceil

from PIL import Image, ImageDraw, ImageEnhance, ImageFilter, ImageFont, ImageOps

from ui import price

WIDTH, HEIGHT = 1280, 720
COVER_HEIGHT = 330
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


def _minimum(groups: list[dict]) -> object | None:
    values = [group.get("min_price") for group in groups if group.get("min_price") is not None]
    return min(values) if values else None


def _draw_market(
    draw: ImageDraw.ImageDraw,
    card: dict,
    source: str,
    label: str,
    color: str,
    box: tuple[int, int, int, int],
    label_font: ImageFont.ImageFont,
    price_font: ImageFont.ImageFont,
    kind_font: ImageFont.ImageFont,
    meta_font: ImageFont.ImageFont,
) -> None:
    x1, y1, x2, y2 = box
    groups = _groups(card, source)
    status = _source_status(card, source, groups)
    draw.rounded_rectangle(box, radius=18, fill=PALETTE["panel"], outline=PALETTE["panel_border"], width=2)
    draw.rounded_rectangle((x1 + 18, y1 + 18, x1 + 24, y1 + 54), radius=3, fill=color)
    draw.text((x1 + 36, y1 + 15), label, font=label_font, fill=PALETTE["ink"])
    if status:
        draw.text((x1 + 36, y1 + 63), status.upper(), font=kind_font, fill=PALETTE["muted"])
        return

    minimum = _minimum(groups)
    draw.text((x1 + 36, y1 + 55), f"от {price(minimum)}", font=price_font, fill=PALETTE["ink"])
    draw.text((x1 + 36, y1 + 96), "МИНИМАЛЬНАЯ ЦЕНА", font=meta_font, fill=PALETTE["muted"])
    if source == "steam":
        draw.text((x1 + 36, y1 + 128), "ОФИЦИАЛЬНЫЙ МАГАЗИН", font=meta_font, fill=PALETTE["muted"])
        return

    kinds_x = x1 + 192
    kind_width = x2 - kinds_x - 20
    columns = 2
    chip_width = kind_width if columns == 1 else (kind_width - 10) // 2
    rows = ceil(len(groups) / columns)
    chip_height = min(42, max(30, (y2 - y1 - 34) // max(rows, 1)))
    for index, group in enumerate(groups):
        row, column = divmod(index, columns)
        chip_x = kinds_x + column * (chip_width + 10)
        chip_y = y1 + 18 + row * chip_height
        label_text = _fit(_kind_label(group).upper(), meta_font, chip_width - 12)
        count = group.get("count") or group.get("offer_count")
        suffix = f" · {count}" if count and source != "steam" else ""
        draw.rounded_rectangle((chip_x, chip_y, chip_x + chip_width, chip_y + chip_height - 5), radius=8, fill=PALETTE["panel_deep"])
        draw.text((chip_x + 10, chip_y + 5), label_text, font=meta_font, fill=PALETTE["muted"])
        draw.text((chip_x + 10, chip_y + 20), _fit(price(group.get("min_price")) + suffix, kind_font, chip_width - 14), font=kind_font, fill=PALETTE["ink"])


def render_card(card: dict, cover_bytes: bytes | None = None) -> bytes:
    result = Image.new("RGB", (WIDTH, HEIGHT), PALETTE["paper"])
    result.paste(_cover(cover_bytes), (0, 0))
    draw = ImageDraw.Draw(result)
    draw.rounded_rectangle((0, COVER_HEIGHT - 24, WIDTH, HEIGHT), radius=28, fill=PALETTE["paper"])
    draw.rectangle((0, COVER_HEIGHT, WIDTH, HEIGHT), fill=PALETTE["paper"])
    draw.rectangle((0, COVER_HEIGHT, WIDTH, COVER_HEIGHT + 4), fill=PALETTE["signal"])

    title_font, price_font = _font(32, True), _font(26, True)
    label_font, kind_font, meta_font = _font(18, True), _font(16, True), _font(12, True)
    steam = card.get("steam") or {}
    name = _fit(str(steam.get("name") or "Игра"), title_font, WIDTH - 360)
    draw.text((36, COVER_HEIGHT + 28), name, font=title_font, fill=PALETTE["ink"])
    status = "ЕЩЁ НЕ ВЫШЛА" if steam.get("note") else "ЦЕНЫ ИЗ СЕРВЕРНОГО ХРАНИЛИЩА"
    draw.text((38, COVER_HEIGHT + 69), status, font=meta_font, fill=PALETTE["muted"])
    draw.rounded_rectangle((WIDTH - 204, COVER_HEIGHT + 28, WIDTH - 36, COVER_HEIGHT + 60), radius=16, fill=PALETTE["signal"])
    draw.text((WIDTH - 184, COVER_HEIGHT + 37), "ИГРОСКАН", font=kind_font, fill="white")

    top = COVER_HEIGHT + 100
    bottom = HEIGHT - 22
    steam_box = (36, top, 310, bottom)
    plati_box = (326, top, 785, bottom)
    ggsel_box = (801, top, WIDTH - 36, bottom)
    _draw_market(draw, card, "steam", "STEAM", PALETTE["cool"], steam_box, label_font, price_font, kind_font, meta_font)
    _draw_market(draw, card, "plati", "PLATI.MARKET", PALETTE["violet"], plati_box, label_font, price_font, kind_font, meta_font)
    _draw_market(draw, card, "ggsel", "GGSEL", PALETTE["signal"], ggsel_box, label_font, price_font, kind_font, meta_font)

    stream = BytesIO()
    result.save(stream, format="PNG", optimize=True)
    return stream.getvalue()
