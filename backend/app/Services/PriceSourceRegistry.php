<?php

namespace App\Services;

use App\Contracts\PriceSourceAdapter;
use App\Services\PriceSources\GgselPriceSourceAdapter;
use App\Services\PriceSources\PlatiPriceSourceAdapter;
use App\Services\PriceSources\SteamPriceSourceAdapter;

class PriceSourceRegistry
{
    /** @var array<string, PriceSourceAdapter> */
    private array $adapters;

    public function __construct(SteamPriceSourceAdapter $steam, PlatiPriceSourceAdapter $plati, GgselPriceSourceAdapter $ggsel)
    {
        $this->adapters = [$steam->source() => $steam, $plati->source() => $plati, $ggsel->source() => $ggsel];
    }

    public function for(string $source): PriceSourceAdapter
    {
        if (! isset($this->adapters[$source])) {
            throw new \InvalidArgumentException("Unsupported price source: {$source}");
        }

        return $this->adapters[$source];
    }
}
