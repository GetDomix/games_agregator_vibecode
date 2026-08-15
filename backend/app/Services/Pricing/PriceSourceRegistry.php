<?php

namespace App\Services\Pricing;

use App\Contracts\PriceSourceAdapter;
use App\Services\Pricing\Adapters\GgselPriceSourceAdapter;
use App\Services\Pricing\Adapters\PlatiPriceSourceAdapter;
use App\Services\Pricing\Adapters\SteamPriceSourceAdapter;

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
