from app.models import OfferLink, ProductKind
from app.services.aggregator import _aggregate_by_kind, _marketplace_stats


def _offer(kind: ProductKind, price: float, sales: int = 0, title: str = "x") -> OfferLink:
    return OfferLink(
        title=title,
        url=f"https://example.com/{title}",
        price_rub=price,
        sales=sales,
        seller_name="Seller",
        kind=kind,
    )


def test_aggregate_min_avg_popular_cheapest():
    offers = [
        _offer(ProductKind.KEY, 300, sales=10, title="k1"),
        _offer(ProductKind.KEY, 100, sales=2, title="k2"),
        _offer(ProductKind.KEY, 200, sales=50, title="k3"),
        _offer(ProductKind.GIFT, 500, sales=1, title="g1"),
    ]
    stats = _aggregate_by_kind(offers)
    by_kind = {s.kind: s for s in stats}

    key = by_kind[ProductKind.KEY]
    assert key.count == 3
    assert key.min_price == 100
    assert key.avg_price == 200.0
    assert key.cheapest is not None and key.cheapest.title == "k2"
    assert key.popular is not None and key.popular.title == "k3"

    gift = by_kind[ProductKind.GIFT]
    assert gift.count == 1
    assert gift.min_price == 500


def test_aggregate_skips_empty_kinds():
    offers = [_offer(ProductKind.ACCOUNT, 150, sales=3)]
    stats = _aggregate_by_kind(offers)
    assert [s.kind for s in stats] == [ProductKind.ACCOUNT]


def test_marketplace_stats_on_error_has_empty_kinds():
    offers = [_offer(ProductKind.KEY, 10)]
    stats = _marketplace_stats("plati", "Plati", offers, total_offers=10, error="timeout")
    assert stats.error == "timeout"
    assert stats.by_kind == []
    assert stats.scanned_offers == 1
