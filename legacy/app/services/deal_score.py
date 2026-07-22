"""Deal score: how much cheaper market min is vs Steam."""

from __future__ import annotations

from app.schemas import DealScore, MarketplaceStats


def _min_from_market(stats: MarketplaceStats | None) -> float | None:
    if stats is None or stats.error:
        return None
    mins = [k.min_price for k in (stats.by_kind or []) if k.min_price is not None]
    return min(mins) if mins else None


def compute_deal_score(
    steam_price_rub: float | None,
    plati: MarketplaceStats | None,
    ggsel: MarketplaceStats | None,
) -> DealScore:
    plati_min = _min_from_market(plati)
    ggsel_min = _min_from_market(ggsel)

    market_min: float | None = None
    market_source: str | None = None
    candidates: list[tuple[str, float]] = []
    if plati_min is not None:
        candidates.append(("plati", plati_min))
    if ggsel_min is not None:
        candidates.append(("ggsel", ggsel_min))
    if candidates:
        market_source, market_min = min(candidates, key=lambda x: x[1])

    if steam_price_rub is None or steam_price_rub <= 0 or market_min is None:
        return DealScore(
            steam_price_rub=steam_price_rub,
            market_min_rub=market_min,
            market_source=market_source,
            savings_rub=None,
            savings_percent=None,
            score=0,
            label="нет сравнения" if market_min is None else "Steam н/д",
            is_better=False,
        )

    savings_rub = round(steam_price_rub - market_min, 2)
    savings_percent = round((savings_rub / steam_price_rub) * 100, 1)
    is_better = savings_rub > 0

    # Map savings % to 0–100 score (cap extreme outliers)
    if not is_better:
        score = max(0, min(15, int(20 + savings_percent)))  # slightly over Steam → low score
        label = "дороже Steam" if savings_rub < -1 else "≈ Steam"
    elif savings_percent >= 40:
        score = 95
        label = "огонь-сделка"
    elif savings_percent >= 25:
        score = 80
        label = "отличная цена"
    elif savings_percent >= 12:
        score = 65
        label = "выгодно"
    elif savings_percent >= 5:
        score = 45
        label = "чуть дешевле"
    else:
        score = 25
        label = "почти как Steam"

    # Fine-tune score by percent within band
    if is_better:
        score = max(score, min(100, int(round(savings_percent * 2.2))))

    return DealScore(
        steam_price_rub=steam_price_rub,
        market_min_rub=market_min,
        market_source=market_source,
        savings_rub=savings_rub,
        savings_percent=savings_percent,
        score=int(max(0, min(100, score))),
        label=label,
        is_better=is_better,
    )
