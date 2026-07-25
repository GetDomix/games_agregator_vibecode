<?php

namespace App\Services\PriceSources;

use App\Contracts\PriceSourceAdapter;
use App\Services\GgselService;

class GgselPriceSourceAdapter extends AbstractMarketplacePriceSourceAdapter implements PriceSourceAdapter
{
    public function __construct(private readonly GgselService $ggsel) {}

    protected function search(string $query): array
    {
        return $this->ggsel->search($query);
    }

    protected function sourceId(): string
    {
        return 'ggsel';
    }
}
