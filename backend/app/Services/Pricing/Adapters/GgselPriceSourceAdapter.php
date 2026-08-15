<?php

namespace App\Services\Pricing\Adapters;

use App\Contracts\PriceSourceAdapter;
use App\Models\Game;
use App\Services\Pricing\GgselService;

class GgselPriceSourceAdapter extends AbstractMarketplacePriceSourceAdapter implements PriceSourceAdapter
{
    public function __construct(private readonly GgselService $ggsel) {}

    protected function searchForGame(Game $game): array
    {
        return $this->ggsel->searchForGame($game);
    }

    protected function sourceId(): string
    {
        return 'ggsel';
    }
}
