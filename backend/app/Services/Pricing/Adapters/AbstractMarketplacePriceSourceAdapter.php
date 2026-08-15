<?php

namespace App\Services\Pricing\Adapters;

use App\Data\PriceSourceResult;
use App\Models\Game;

abstract class AbstractMarketplacePriceSourceAdapter
{
    abstract protected function sourceId(): string;

    /**
     * Fetch catalog-scoped offers for a persisted game (with id cache).
     *
     * @return array{0: list<array<string, mixed>>, 1: int, 2: ?string}
     */
    abstract protected function searchForGame(Game $game): array;

    public function source(): string
    {
        return $this->sourceId();
    }

    public function refresh(Game $game): PriceSourceResult
    {
        // Platform game-card path: no free-text OfferRelevance filter.
        [$offers, , $lastError] = $this->searchForGame($game);
        if ($offers === [] && $lastError) {
            throw new \RuntimeException((string) $lastError);
        }

        return new PriceSourceResult($this->sourceId(), $this->aggregate($offers));
    }

    /** @param list<array<string, mixed>> $offers */
    protected function aggregate(array $offers): array
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
                'currency_prices' => $this->currencyPrices($items),
                'offer_count' => count($items),
                'cheapest' => $cheapest,
                'popular' => $popular,
            ];
        }

        return $result;
    }

    /** @return array<string, array<string, mixed>> */
    private function currencyPrices(array $items): array
    {
        $result = [];
        foreach (['RUB', 'USD', 'EUR'] as $currency) {
            $priced = array_values(array_filter($items, static fn (array $item): bool => isset($item['prices'][$currency]) && is_numeric($item['prices'][$currency])));
            if ($priced === []) {
                continue;
            }
            usort($priced, static fn (array $a, array $b): int => (float) $a['prices'][$currency] <=> (float) $b['prices'][$currency]);
            $popular = $priced[0];
            foreach ($priced as $item) {
                if (($item['sales'] ?? 0) > ($popular['sales'] ?? 0)) {
                    $popular = $item;
                }
            }
            $values = array_map(static fn (array $item): float => (float) $item['prices'][$currency], $priced);
            $result[$currency] = [
                'min' => round(min($values), 2),
                'avg' => round(array_sum($values) / count($values), 2),
                'cheapest' => $this->currencyOffer($priced[0], $currency),
                'popular' => $this->currencyOffer($popular, $currency),
            ];
        }

        return $result;
    }

    private function currencyOffer(array $offer, string $currency): array
    {
        return [
            'title' => $offer['title'] ?? null,
            'url' => $offer['url'] ?? null,
            'price' => round((float) $offer['prices'][$currency], 2),
            'price_rub' => round((float) $offer['price_rub'], 2),
            'sales' => (int) ($offer['sales'] ?? 0),
        ];
    }
}
