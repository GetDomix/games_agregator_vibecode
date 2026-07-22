"""Ad inventory scaffolding — placeholders until partner agreements exist."""

from __future__ import annotations

from app.config import Settings
from app.schemas import AdSlot, AdsConfigResponse

# Standard IAB-ish sizes used as layout hints for future creatives
DEFAULT_SLOTS: list[dict] = [
    {
        "id": "header_leaderboard",
        "placement": "header",
        "format": "leaderboard",
        "size_hint": "728×90 / 970×90",
        "title": "Рекламный слот · Header",
        "subtitle": "Лидерборд над поиском. Скоро здесь будут баннеры партнёров.",
    },
    {
        "id": "mid_billboard",
        "placement": "mid",
        "format": "billboard",
        "size_hint": "970×250",
        "title": "Билборд · между поиском и результатами",
        "subtitle": "Широкий баннер. Идеально для ключей, лаунчеров и сервисов.",
    },
    {
        "id": "results_inline",
        "placement": "inline_results",
        "format": "rectangle",
        "size_hint": "300×250",
        "title": "Слот в блоке результатов",
        "subtitle": "Показывается после карточки Steam, до таблиц маркетплейсов.",
    },
    {
        "id": "footer_leaderboard",
        "placement": "footer",
        "format": "leaderboard",
        "size_hint": "728×90",
        "title": "Рекламный слот · Footer",
        "subtitle": "Нижний лидерборд. Заготовка под РСЯ / AdSense / прямые продажи.",
    },
]


def build_ads_config(settings: Settings) -> AdsConfigResponse:
    slots: list[AdSlot] = []
    if settings.ads_enabled:
        for raw in DEFAULT_SLOTS:
            slots.append(
                AdSlot(
                    **raw,
                    cta="Разместить рекламу",
                    provider="placeholder",
                    # mailto as temporary CTA until a real network is wired
                    click_url=f"mailto:{settings.ads_contact_email}?subject=Реклама%20на%20Game%20Price%20Aggregator%20({raw['id']})",
                )
            )

    return AdsConfigResponse(
        enabled=settings.ads_enabled,
        contact_email=settings.ads_contact_email,
        label=settings.ads_label,
        note=(
            "Заготовки билбордов: соглашений с рекламными сетями пока нет. "
            "Слоты готовы к подключению (provider=placeholder). "
            f"По вопросам размещения: {settings.ads_contact_email}"
        ),
        slots=slots,
    )
