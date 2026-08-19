<?php

namespace App\Data;

/**
 * A successful, normalized snapshot from one price source.
 * Each offer group has kind, min_price_rub, avg_price_rub, offer_count,
 * cheapest array and an optional popular array compatible with current_game_prices fields.
 * Popular is null when the marketplace does not publish sales counts.
 */
final readonly class PriceSourceResult
{
    public function __construct(
        public string $source,
        public array $offerGroups,
        public ?string $gameName = null,
        public ?string $headerImage = null,
        public ?string $releaseStatus = null,
        public ?string $releaseDate = null,
        public array $regionalPrices = [],
        public ?int $discountPercent = null,
        public ?float $priceInitialRub = null,
    ) {}
}
