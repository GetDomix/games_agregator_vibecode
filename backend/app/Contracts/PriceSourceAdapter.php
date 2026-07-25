<?php

namespace App\Contracts;

use App\Data\PriceSourceResult;
use App\Models\Game;

interface PriceSourceAdapter
{
    public function source(): string;

    /** @throws \Throwable when the source response is not valid */
    public function refresh(Game $game): PriceSourceResult;
}
