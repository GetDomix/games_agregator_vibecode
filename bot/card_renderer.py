from __future__ import annotations

from io import BytesIO
from PIL import Image, ImageDraw, ImageFont, ImageOps

from ui import price

WIDTH, HEIGHT = 1200, 630
PALETTE = {"paper": "#121925", "ink": "#f2ebe0", "muted": "#a9b3c5", "signal": "#ff6b4a", "amber": "#f59e0b", "steam": "#1b9fff", "plati": "#9a78df", "ggsel": "#df7b3e"}


def _font(size: int, bold: bool = False) -> ImageFont.FreeTypeFont | ImageFont.ImageFont:
    names = ["DejaVuSans-Bold.ttf" if bold else "DejaVuSans.ttf", "arialbd.ttf" if bold else "arial.ttf"]
    for name in names:
        try:
            return ImageFont.truetype(name, size)
        except OSError:
            continue
    return ImageFont.load_default()


def _fit(text: str, font: ImageFont.ImageFont, max_width: int) -> str:
    if ImageDraw.Draw(Image.new("RGB", (1, 1))).textlength(text, font=font) <= max_width:
        return text
    while text and ImageDraw.Draw(Image.new("RGB", (1, 1))).textlength(text + "…", font=font) > max_width:
        text = text[:-1]
    return text + "…"


def _cover(image_bytes: bytes | None) -> Image.Image:
    if not image_bytes:
        return Image.new("RGB", (520, HEIGHT), "#27344b")
    try:
        image = Image.open(BytesIO(image_bytes)).convert("RGB")
        return ImageOps.fit(image, (520, HEIGHT), method=Image.Resampling.LANCZOS)
    except Exception:
        return Image.new("RGB", (520, HEIGHT), "#27344b")


def render_card(card: dict, cover_bytes: bytes | None = None) -> bytes:
    result = Image.new("RGB", (WIDTH, HEIGHT), PALETTE["paper"])
    result.paste(_cover(cover_bytes), (0, 0))
    shade = Image.new("RGBA", (WIDTH, HEIGHT), (8, 14, 22, 0))
    draw_shade = ImageDraw.Draw(shade)
    draw_shade.rectangle((0, 0, 600, HEIGHT), fill=(8, 14, 22, 90))
    draw_shade.polygon([(420, 0), (650, 0), (490, HEIGHT), (340, HEIGHT)], fill=(18, 25, 37, 215))
    result = Image.alpha_composite(result.convert("RGBA"), shade).convert("RGB")
    draw = ImageDraw.Draw(result)
    title_font, price_font, body_font, small_font = _font(42, True), _font(34, True), _font(22), _font(18, True)
    steam = card.get("steam") or {}
    name = _fit(str(steam.get("name") or "Игра"), title_font, 610)
    draw.rounded_rectangle((555, 38, 750, 76), radius=19, fill=PALETTE["signal"])
    draw.text((575, 47), "ИГРОСКАН · ЦЕНЫ", font=small_font, fill="white")
    draw.text((555, 105), name, font=title_font, fill=PALETTE["ink"])
    status = "ЕЩЁ НЕ ВЫШЛА" if (steam.get("note") or "") else "АКТУАЛЬНЫЕ ПРЕДЛОЖЕНИЯ"
    draw.text((558, 163), status, font=small_font, fill=PALETTE["amber"])
    rows = [("STEAM", price(steam.get("price_rub")), PALETTE["steam"])]
    for key, label, color in (("plati", "PLATI", PALETTE["plati"]), ("ggsel", "GGSEL", PALETTE["ggsel"])):
        groups = (card.get(key) or {}).get("by_kind") or []
        value = min((float(g["min_price"]) for g in groups if g.get("min_price") is not None), default=None)
        rows.append((label, price(value), color))
    y = 225
    for label, value, color in rows:
        draw.rounded_rectangle((555, y, 1145, y + 92), radius=16, fill="#182233", outline="#2b3a52")
        draw.rounded_rectangle((573, y + 18, 585, y + 74), radius=6, fill=color)
        draw.text((607, y + 20), label, font=small_font, fill=PALETTE["muted"])
        draw.text((607, y + 45), value, font=price_font, fill=PALETTE["ink"])
        y += 105
    freshness = "обновление в фоне" if card.get("refreshing") else "цены из сервера"
    draw.text((555, 565), f"● {freshness}", font=body_font, fill=PALETTE["muted"])
    stream = BytesIO()
    result.save(stream, format="PNG", optimize=True)
    return stream.getvalue()
