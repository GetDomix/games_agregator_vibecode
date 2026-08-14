<?php

namespace App\Services\Catalog;

/**
 * How we query grey-market search APIs for a game already resolved on Steam.
 *
 * Only surface forms of the *same full* Steam title — never a truncated
 * franchise prefix (that floods results with other products in the series).
 */
class MarketQuery
{
    /**
     * @return list<string>
     */
    public static function variants(string $steamName): array
    {
        $full = trim($steamName);
        if ($full === '') {
            return [];
        }

        $variants = [$full];

        // Same tokens, punctuation as spaces — digiseller/elastic often rank this better.
        $plain = self::plain($full);
        if ($plain !== '' && mb_strtolower($plain) !== mb_strtolower($full)) {
            $variants[] = $plain;
        }

        return array_values(array_unique($variants));
    }

    public static function plain(string $name): string
    {
        $plain = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $name) ?? $name;

        return trim(preg_replace('/\s+/u', ' ', $plain) ?? $plain);
    }

    /**
     * Merge offers from all variants (dedupe by url, else title+price).
     *
     * @param  callable(string): array{0: list<array<string, mixed>>, 1: int, 2: ?string}  $search
     * @return array{0: list<array<string, mixed>>, 1: int, 2: ?string}
     */
    public static function searchMerged(callable $search, string $steamName): array
    {
        $merged = [];
        $seen = [];
        $lastError = null;

        foreach (self::variants($steamName) as $variant) {
            [$offers, , $error] = $search($variant);
            if ($error) {
                $lastError = $error;
            }
            foreach ($offers as $offer) {
                $key = (string) ($offer['url'] ?? (($offer['title'] ?? '').'|'.($offer['price_rub'] ?? '')));
                if ($key === '' || isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $merged[] = $offer;
            }
        }

        if ($merged === [] && $lastError) {
            return [[], 0, $lastError];
        }

        return [$merged, count($merged), null];
    }
}
