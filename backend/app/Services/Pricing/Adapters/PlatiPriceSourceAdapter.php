<?php

namespace App\Services\Pricing\Adapters;

use App\Contracts\PriceSourceAdapter;
use App\Models\Game;
use App\Services\Pricing\PlatiService;

class PlatiPriceSourceAdapter extends AbstractMarketplacePriceSourceAdapter implements PriceSourceAdapter
{
    public function __construct(private readonly PlatiService $plati) {}

    protected function searchForGame(Game $game): array
    {
        return $this->plati->searchForGame($game);
    }

    protected function sourceId(): string
    {
        return 'plati';
    }
}
