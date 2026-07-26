<?php

namespace App\Data;

/**
 * A successful, normalized snapshot from one price source.
 * Each offer group has kind, min_price_rub, avg_price_rub, offer_count,
 * cheapest and popular arrays compatible with current_game_prices fields.
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
    ) {}
}
