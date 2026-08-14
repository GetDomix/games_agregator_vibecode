<?php

namespace App\Services\Catalog;

/**
 * Keep marketplace lots that refer to the Steam-resolved game; drop lookalikes.
 *
 * Pipeline is general (no per-title special cases):
 *
 *  1. Resolve tokens of the Steam name.
 *  2. Reject lots with digit tokens the Steam name does not have (years / sequels).
 *  3. Accept if the full plain Steam name is a substring of the lot title.
 *  4. Otherwise require enough Steam tokens in the lot (all of them for short
 *     titles, all-but-one for long ones) without competing content words.
 *  5. Sellers often drop a shared series prefix and keep the product suffix:
 *     one hit on the unique longest non-first token may pass.
 *  6. Commerce words (steam, key, region…) are noise, not content.
 */
class OfferRelevance
{
    /** Commerce / platform vocabulary shared across grey-market lots. */
    private const MARKET_NOISE = [
        'steam', 'key', 'ключ', 'keys', 'ключи', 'аккаунт', 'account', 'accounts',
        'gift', 'гифт', 'подарок', 'подарочный', 'ru', 'рус', 'рф', 'россия', 'russia',
        'region', 'регион', 'global', 'глобал', 'pc', 'пк', 'random', 'рандом',
        'sale', 'скидка', 'buy', 'покупка', 'активация', 'activation', 'версия',
        'издание', 'edition', 'снг', 'eu', 'европа', 'usa', 'сша', 'турция', 'tr',
        'epic', 'game', 'games', 'store', 'digital', 'license', 'лицензия',
        'авто', 'автодоставка', 'мгновенно', 'delivery', 'offline', 'online',
        // platform / edition tags sellers glue onto any title
        'nintendo', 'switch', 'xbox', 'playstation', 'ps4', 'ps5', 'gog', 'origin',
        'uplay', 'ea', 'battle', 'net', 'deluxe', 'goty', 'complete', 'definitive',
        'ultimate', 'gold', 'standard', 'premium', 'remaster', 'remastered', 'enhanced',
        'anniversary', 'collection', 'dlc', 'bundle', 'pack',
    ];

    private const STOP = [
        'the', 'a', 'an', 'and', 'or', 'of', 'to', 'for', 'и', 'для', 'на', 'с', 'в',
    ];

    /** @param list<array<string, mixed>> $offers @return list<array<string, mixed>> */
    public static function filter(array $offers, string $gameName): array
    {
        $gameTokens = self::tokens($gameName);
        if ($gameTokens === [] || $offers === []) {
            return $offers;
        }

        $kept = [];
        foreach ($offers as $offer) {
            $title = trim((string) ($offer['title'] ?? ''));
            if ($title === '') {
                continue;
            }
            $score = self::score($title, $gameTokens, $gameName);
            if ($score < 0.5) {
                continue;
            }
            $offer['_relevance'] = round($score, 3);
            $kept[] = $offer;
        }

        return $kept;
    }

    /** @param list<string> $gameTokens */
    public static function score(string $title, array $gameTokens, string $gameName): float
    {
        $titleLower = mb_strtolower($title);
        $titleTokens = self::tokens($title);
        if ($titleTokens === []) {
            return 0.0;
        }

        $gameDigits = self::digitTokens($gameTokens);
        $titleDigits = self::digitTokens($titleTokens);
        $foreignDigits = array_values(array_diff($titleDigits, $gameDigits));
        if ($foreignDigits !== []) {
            return 0.0;
        }

        $n = count($gameTokens);
        $extras = self::extraContentTokens($titleTokens, $gameTokens);

        $plainGame = mb_strtolower(MarketQuery::plain($gameName));
        $fullNameHit = $plainGame !== '' && str_contains($titleLower, $plainGame);

        // Short Steam names ("Hades") are substrings of sequels ("Hades II") and
        // costume packs. Any extra non-commerce word means another product.
        if ($fullNameHit) {
            if ($n <= 2 && $extras !== []) {
                return 0.0;
            }

            return 1.0;
        }

        $hits = [];
        foreach ($gameTokens as $gt) {
            foreach ($titleTokens as $tt) {
                if (self::tokenMatch($tt, $gt)) {
                    $hits[] = $gt;
                    break;
                }
            }
        }
        $hitCount = count($hits);
        if ($hitCount === 0) {
            return 0.0;
        }

        // Full token set present but order broken beyond a one-word gap.
        if ($hitCount === $n && self::adjacencyScore($gameTokens, $titleTokens) < 0.5) {
            return 0.0;
        }

        // Incomplete Steam name + other content words → different product.
        if ($hitCount < $n && $extras !== [] && (count($extras) >= 2 || count($extras) >= $hitCount)) {
            return 0.0;
        }

        if ($hitCount >= self::requiredHits($n)) {
            if ($n <= 2 && $extras !== []) {
                return 0.0;
            }

            return max(0.5, $hitCount / $n);
        }

        // Distinctive product suffix without the shared series prefix.
        if (self::isDistinctiveSuffixHit($hits, $gameTokens, $extras)) {
            return 0.75;
        }

        // Partial coverage that fails the required-hit bar is a hard reject.
        return 0.0;
    }

    /**
     * How many Steam tokens a lot must mention.
     * Short titles: all. Long titles: allow sellers to drop one word.
     */
    private static function requiredHits(int $tokenCount): int
    {
        if ($tokenCount <= 0) {
            return 0;
        }
        if ($tokenCount <= 3) {
            return $tokenCount;
        }

        return $tokenCount - 1;
    }

    /**
     * @param  list<string>  $hits
     * @param  list<string>  $gameTokens
     * @param  list<string>  $extras
     */
    private static function isDistinctiveSuffixHit(array $hits, array $gameTokens, array $extras): bool
    {
        if (count($hits) !== 1 || $extras !== [] || count($gameTokens) < 2) {
            return false;
        }

        $matched = $hits[0];
        // Leading token alone is usually the shared franchise/series name.
        if ($matched === $gameTokens[0]) {
            return false;
        }

        $maxLen = 0;
        foreach ($gameTokens as $tok) {
            $maxLen = max($maxLen, mb_strlen($tok));
        }
        if (mb_strlen($matched) < $maxLen || $maxLen < 5) {
            return false;
        }

        $longestCount = 0;
        foreach ($gameTokens as $tok) {
            if (mb_strlen($tok) === $maxLen) {
                $longestCount++;
            }
        }

        return $longestCount === 1;
    }

    /** @param list<string> $titleTokens @param list<string> $gameTokens @return list<string> */
    private static function extraContentTokens(array $titleTokens, array $gameTokens): array
    {
        $extras = [];
        foreach ($titleTokens as $tt) {
            if (in_array($tt, self::MARKET_NOISE, true)) {
                continue;
            }
            $covered = false;
            foreach ($gameTokens as $gt) {
                if (self::tokenMatch($tt, $gt)) {
                    $covered = true;
                    break;
                }
            }
            if (! $covered) {
                $extras[] = $tt;
            }
        }

        return $extras;
    }

    private static function tokenMatch(string $a, string $b): bool
    {
        if ($a === $b) {
            return true;
        }

        return abs(mb_strlen($a) - mb_strlen($b)) <= 1
            && (str_starts_with($a, $b) || str_starts_with($b, $a));
    }

    /** @param list<string> $tokens @return list<string> */
    private static function digitTokens(array $tokens): array
    {
        return array_values(array_filter($tokens, static fn (string $t): bool => (bool) preg_match('/^\d+$/', $t)));
    }

    /**
     * @param  list<string>  $gameTokens
     * @param  list<string>  $titleTokens
     */
    private static function adjacencyScore(array $gameTokens, array $titleTokens): float
    {
        if (count($gameTokens) < 3) {
            return 1.0;
        }

        $bigrams = [];
        for ($i = 0, $n = count($gameTokens); $i < $n - 1; $i++) {
            $bigrams[] = [$gameTokens[$i], $gameTokens[$i + 1]];
        }

        $found = 0;
        foreach ($bigrams as [$a, $b]) {
            for ($i = 0, $n = count($titleTokens); $i < $n - 1; $i++) {
                if ($titleTokens[$i] === $a && ($titleTokens[$i + 1] === $b || ($i + 2 < $n && $titleTokens[$i + 2] === $b))) {
                    $found++;
                    break;
                }
            }
        }

        return $found / count($bigrams);
    }

    /** @return list<string> */
    public static function tokens(string $name): array
    {
        $n = mb_strtolower(MarketQuery::plain($name));
        $parts = preg_split('/\s+/u', $n) ?: [];
        $out = [];
        foreach ($parts as $p) {
            if ($p === '' || mb_strlen($p) < 2 || in_array($p, self::STOP, true)) {
                continue;
            }
            $out[] = $p;
        }

        return array_values(array_unique($out));
    }
}
