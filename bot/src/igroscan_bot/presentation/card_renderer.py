from __future__ import annotations

from io import BytesIO
from pathlib import Path

from PIL import Image, ImageDraw, ImageFont, ImageOps

from .ui import official_price, price

WIDTH, HEIGHT = 1672, 942
COVER_HEIGHT = 438
ASSETS = Path(__file__).resolve().parents[3] / "assets"
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
    """Use the game's real wide illustration as the visual header."""
    canvas = Image.new("RGB", (WIDTH, COVER_HEIGHT), "#111722")
    if not image_bytes:
        return canvas
    try:
        image = Image.open(BytesIO(image_bytes)).convert("RGB")
    except Exception:
        return canvas

    return ImageOps.fit(image, canvas.size, method=Image.Resampling.LANCZOS, centering=(0.5, 0.5))


def _groups(card: dict, source: str) -> list[dict]:
    if source == "steam":
        steam = card.get("steam") or {}
        return [{"label": "Официально", "min_price": steam.get("price_rub"), "count": 1}]
    return list((card.get(source) or {}).get("by_kind") or [])


def _kind_label(group: dict) -> str:
    labels = {"official": "Официально", "key": "Ключ", "gift": "Гифт", "account": "Аккаунт", "rent": "Аренда"}
    return str(group.get("label") or labels.get(group.get("kind"), group.get("kind") or "Предложение"))


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


def _draw_store_identity(
    result: Image.Image,
    draw: ImageDraw.ImageDraw,
    source: str,
    label: str,
    box: tuple[int, int, int, int],
    meta_font: ImageFont.ImageFont,
) -> None:
    x1, y1, x2, y2 = box
    logo = _logo(source, 74)
    logo_x = x1 + (x2 - x1 - logo.width) // 2
    result.paste(logo, (logo_x, y1 + 12), logo)
    draw.text(((x1 + x2) // 2, y2 - 13), label, font=meta_font, fill=PALETTE["ink"], anchor="ms")


def _draw_offer_row(
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
    draw.rounded_rectangle(box, radius=14, fill=PALETTE["panel_deep"], outline=PALETTE["panel_border"], width=2)
    identity_right = x1 + 170
    _draw_store_identity(result, draw, source, label, (x1, y1, identity_right, y2), meta_font)
    draw.line((identity_right, y1 + 6, identity_right, y2 - 6), fill=PALETTE["panel_border"], width=2)
    groups_by_kind = {str(group.get("kind")): group for group in _groups(card, source)}
    steam_price = (card.get("steam") or {}).get("price_rub")
    offer_left = identity_right
    offer_width = (x2 - offer_left) // 4
    for index, kind in enumerate(("key", "gift", "account", "rent")):
        cell_left = offer_left + index * offer_width
        cell_right = x2 if index == 3 else cell_left + offer_width
        if index:
            draw.line((cell_left, y1 + 6, cell_left, y2 - 6), fill=PALETTE["panel_border"], width=2)
        group = groups_by_kind.get(kind)
        amount = group.get("min_price") if group else None
        count = (group.get("count") or group.get("offer_count")) if group else None
        draw.text((cell_left + 38, y1 + 24), _kind_label({"kind": kind}).upper(), font=kind_font, fill=PALETTE["muted"])
        draw.text((cell_left + 38, y1 + 57), price(amount) if amount is not None else "—", font=price_font, fill=_price_tone(amount, steam_price))
        if count:
            tone = _price_tone(amount, steam_price)
            arrow = "↑" if tone == PALETTE["bad"] else "↓"
            draw.text((cell_right - 38, y1 + 70), f"{arrow} {count}", font=kind_font, fill=tone, anchor="rs")


def render_card(card: dict, cover_bytes: bytes | None = None) -> bytes:
    result = Image.new("RGB", (WIDTH, HEIGHT), PALETTE["paper"])
    result.paste(_cover(cover_bytes), (0, 0))
    draw = ImageDraw.Draw(result)
    panel = (42, COVER_HEIGHT, WIDTH - 42, HEIGHT - 30)
    draw.rounded_rectangle(panel, radius=18, fill=PALETTE["paper"], outline=PALETTE["panel_border"], width=2)

    title_font, price_font = _font(42, True), _font(42, True)
    kind_font, meta_font = _font(20, True), _font(17, True)
    steam = card.get("steam") or {}
    name = _fit(str(steam.get("name") or "Игра"), title_font, WIDTH - 180)
    draw.text((90, COVER_HEIGHT + 26), name, font=title_font, fill=PALETTE["ink"])

    steam_box = (84, COVER_HEIGHT + 74, WIDTH - 84, COVER_HEIGHT + 194)
    draw.rounded_rectangle(steam_box, radius=14, fill=PALETTE["panel_deep"], outline=PALETTE["panel_border"], width=2)
    _draw_store_identity(result, draw, "steam", "Steam", (steam_box[0], steam_box[1], steam_box[0] + 170, steam_box[3]), meta_font)
    draw.line((steam_box[0] + 170, steam_box[1] + 6, steam_box[0] + 170, steam_box[3] - 6), fill=PALETTE["panel_border"], width=2)
    draw.text((steam_box[0] + 208, steam_box[1] + 27), "ОФИЦИАЛЬНАЯ ЦЕНА", font=kind_font, fill=PALETTE["muted"])
    draw.text((steam_box[0] + 208, steam_box[1] + 61), official_price(steam), font=price_font, fill=PALETTE["ink"])

    _draw_offer_row(result, draw, card, "plati", "Plati.Market", (84, COVER_HEIGHT + 202, WIDTH - 84, COVER_HEIGHT + 322), price_font, kind_font, meta_font)
    _draw_offer_row(result, draw, card, "ggsel", "GGSEL", (84, COVER_HEIGHT + 334, WIDTH - 84, COVER_HEIGHT + 454), price_font, kind_font, meta_font)

    stream = BytesIO()
    result.save(stream, format="PNG", optimize=True)
    return stream.getvalue()
