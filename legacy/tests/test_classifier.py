from app.models import ProductKind
from app.services.classifier import classify_from_text, classify_ggsel, classify_plati


def test_classify_rent():
    assert classify_from_text("Hades аренда аккаунта 7 дней") == ProductKind.RENT
    assert classify_from_text("Account rent 24h") == ProductKind.RENT


def test_classify_gift():
    assert classify_from_text("Steam Gift Hades RU") == ProductKind.GIFT
    assert classify_from_text("Гифт Hades") == ProductKind.GIFT


def test_classify_account():
    assert classify_from_text("Hades оффлайн аккаунт") == ProductKind.ACCOUNT
    assert classify_from_text("shared account steam") == ProductKind.ACCOUNT


def test_classify_key():
    assert classify_from_text("Hades Steam Key GLOBAL") == ProductKind.KEY
    assert classify_from_text("ключ GOG") == ProductKind.KEY


def test_classify_priority_rent_over_account():
    # rent is more specific and checked first
    assert classify_from_text("аренда аккаунта Hades") == ProductKind.RENT


def test_classify_ggsel_by_content_type():
    assert classify_ggsel(1, "anything") == ProductKind.ACCOUNT
    assert classify_ggsel(25, "anything") == ProductKind.RENT
    assert classify_ggsel(48, "anything") == ProductKind.GIFT
    assert classify_ggsel(2, "anything") == ProductKind.KEY


def test_classify_ggsel_fallback_to_text():
    assert classify_ggsel(9999, "Steam Gift Cyberpunk") == ProductKind.GIFT


def test_classify_plati_uses_description():
    assert classify_plati("Cyberpunk 2077", "аренда на 30 дней") == ProductKind.RENT


def test_classify_other():
    assert classify_from_text("просто какой-то товар") == ProductKind.OTHER
