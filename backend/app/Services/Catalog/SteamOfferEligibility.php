<?php

namespace App\Services\Catalog;

/**
 * Keep marketplace offers that can plausibly be used for the Steam/PC game.
 * Catalog cards often mix Steam with console and competing PC-store products.
 */
final class SteamOfferEligibility
{
    /** @param list<array<string, mixed>> $offers @return list<array<string, mixed>> */
    public static function filter(array $offers, string $gameName = ''): array
    {
        $canonical = mb_strtolower(MarketQuery::plain($gameName));

        return array_values(array_filter($offers, static function (array $offer) use ($canonical): bool {
            $title = mb_strtolower(MarketQuery::plain((string) ($offer['title'] ?? '')));
            if ($title === '') {
                return false;
            }
            // A platform word may legitimately be part of the game's own name
            // (for example, Epic Mickey). Inspect only the remaining seller tags.
            $tags = $canonical === '' ? $title : str_replace($canonical, ' ', $title);
            $tags = preg_replace('/\s+/u', ' ', $tags) ?? $tags;

            return ! preg_match(
                '/(?<![\p{L}\p{N}])(?:xbox(?:\s*(?:one|series\s*[xs]))?|иксбокс|playstation|плейстейшн|ps\s*[345]|пс\s*[345]|nintendo\s+switch|gog(?:\s+com)?|epic(?:\s+(?:games|store))?|origin|ea\s+app|uplay|ubisoft\s+connect|battle\s+net|microsoft\s+store|windows\s+store|gfn|geforce\s+now|play\s*key|my\s+games\s+cloud|cloud\s+gaming)(?![\p{L}\p{N}])/iu',
                $tags
            );
        }));
    }
}
