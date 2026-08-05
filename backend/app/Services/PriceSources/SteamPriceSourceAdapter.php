<?php

namespace App\Services\PriceSources;

use App\Contracts\PriceSourceAdapter;
use App\Data\PriceSourceResult;
use App\Models\CurrentGamePrice;
use App\Models\Game;
use App\Models\GameSourceState;
use App\Services\SteamService;

class SteamPriceSourceAdapter implements PriceSourceAdapter
{
    public function __construct(private readonly SteamService $steam) {}

    public function source(): string
    {
        return GameSourceState::SOURCE_STEAM;
    }

    public function refresh(Game $game): PriceSourceResult
    {
        $details = $this->steam->refreshDetails((int) $game->steam_appid, $game->name);
        $groups = [];
        if ($details['price_rub'] !== null) {
            $groups[] = [
                'kind' => CurrentGamePrice::OFFER_KIND_OFFICIAL,
                'min_price_rub' => $details['price_rub'],
                'avg_price_rub' => null,
                'offer_count' => 1,
                'cheapest' => ['title' => $details['name'], 'url' => $details['store_url'], 'price_rub' => $details['price_rub'], 'sales' => null],
                'popular' => ['title' => $details['name'], 'url' => $details['store_url'], 'price_rub' => $details['price_rub'], 'sales' => null],
            ];
        }

        return new PriceSourceResult(
            source: $this->source(),
            offerGroups: $groups,
            gameName: $details['name'] ?? null,
            headerImage: $details['header_image'] ?? null,
            releaseStatus: $details['release_status'] ?? null,
            releaseDate: $details['release_date'] ?? null,
            regionalPrices: $details['regional_prices'] ?? [],
            discountPercent: $details['discount_percent'] ?? null,
            priceInitialRub: $details['price_initial_rub'] ?? null,
        );
    }
}
