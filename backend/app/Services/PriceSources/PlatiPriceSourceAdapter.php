<?php

namespace App\Services\PriceSources;

use App\Contracts\PriceSourceAdapter;
use App\Services\PlatiService;

class PlatiPriceSourceAdapter extends AbstractMarketplacePriceSourceAdapter implements PriceSourceAdapter
{
    public function __construct(private readonly PlatiService $plati) {}

    protected function search(string $query): array
    {
        return $this->plati->search($query);
    }

    protected function sourceId(): string
    {
        return 'plati';
    }
}
