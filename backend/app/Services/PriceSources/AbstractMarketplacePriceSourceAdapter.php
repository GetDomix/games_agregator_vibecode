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
        $merged = [];
        $seen = [];
        $lastError = null;
        foreach ($this->queryVariants($game->name) as $variant) {
            [$offers, , $error] = $this->search($variant);
            if ($error) {
                $lastError = $error;
            }
            foreach ($offers as $offer) {
                $key = $offer['url'] ?? (($offer['title'] ?? '').'|'.($offer['price_rub'] ?? ''));
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $merged[] = $offer;
            }
        }
        if ($merged === [] && $lastError) {
            throw new \RuntimeException((string) $lastError);
        }

        $offers = OfferRelevance::filter(array_values($merged), $game->name);

        return new PriceSourceResult($this->sourceId(), $this->aggregate($offers));
    }

    /** @return list<string> */
    protected function queryVariants(string $name): array
    {
        $full = trim($name);
        $variants = [];
        if ($full !== '') {
            $variants[] = $full;
        }

        $cutAt = null;
        foreach ([' — ', ' - ', ': '] as $sep) {
            $pos = mb_strpos($full, $sep);
            if ($pos !== false && ($cutAt === null || $pos < $cutAt)) {
                $cutAt = $pos;
            }
        }
        if ($cutAt !== null) {
            $short = trim(mb_substr($full, 0, $cutAt));
            if (mb_strlen($short) >= 3) {
                $variants[] = $short;
            }
        }

        return array_values(array_unique($variants));
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
