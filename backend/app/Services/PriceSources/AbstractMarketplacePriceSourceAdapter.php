<?php

namespace App\Services\PriceSources;

use App\Data\PriceSourceResult;
use App\Models\Game;
use App\Services\OfferRelevance;

abstract class AbstractMarketplacePriceSourceAdapter
{
    abstract protected function search(string $query): array;

    abstract protected function sourceId(): string;

    public function source(): string
    {
        return $this->sourceId();
    }

    public function refresh(Game $game): PriceSourceResult
    {
        [$offers, , $error] = $this->search($game->name);
        if ($error) {
            throw new \RuntimeException((string) $error);
        }

        $offers = OfferRelevance::filter($offers, $game->name);

        return new PriceSourceResult($this->sourceId(), $this->aggregate($offers));
    }

    private function aggregate(array $offers): array
    {
        $groups = [];
        foreach ($offers as $offer) {
            $kind = $offer['kind'] ?? 'other';
            $groups[$kind][] = $offer;
        }

        $result = [];
        foreach ($groups as $kind => $items) {
            $prices = array_column($items, 'price_rub');
            $cheapest = $items[0];
            $popular = $items[0];
            foreach ($items as $item) {
                if ((float) $item['price_rub'] < (float) $cheapest['price_rub']) {
                    $cheapest = $item;
                }
                if (($item['sales'] ?? 0) > ($popular['sales'] ?? 0)
                    || (($item['sales'] ?? 0) === ($popular['sales'] ?? 0)
                        && (float) $item['price_rub'] < (float) $popular['price_rub'])) {
                    $popular = $item;
                }
            }
            $result[] = [
                'kind' => $kind,
                'min_price_rub' => round((float) min($prices), 2),
                'avg_price_rub' => round(array_sum($prices) / count($prices), 2),
                'offer_count' => count($items),
                'cheapest' => $cheapest,
                'popular' => $popular,
            ];
        }

        return $result;
    }
}
