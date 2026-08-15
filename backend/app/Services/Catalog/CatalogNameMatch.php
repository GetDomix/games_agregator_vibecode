<?php

namespace App\Services\Catalog;

use Illuminate\Support\Str;

/**
 * Match a Steam-resolved title to a marketplace catalog entity
 * (Plati game card, GGsel category chip, …).
 *
 * Sellers name lots arbitrarily; the platform's own game/category list is the source of truth.
 */
class CatalogNameMatch
{
    /**
     * @param  list<array{name: string, ...}>  $entities
     * @return array{name: string, ...}|null
     */
    public static function pick(array $entities, string $steamName): ?array
    {
        if ($entities === [] || trim($steamName) === '') {
            return null;
        }

        $target = self::norm($steamName);
        $targetPlain = self::norm(MarketQuery::plain($steamName));

        $exact = null;
        $plain = null;
        $starts = null;
        $contains = null;
        $orderedCoverage = null;

        foreach ($entities as $entity) {
            $name = trim((string) ($entity['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $n = self::norm($name);
            $np = self::norm(MarketQuery::plain($name));
            $hasForeignDigits = self::hasForeignDigitTokens($targetPlain, $np);

            if ($n === $target || $np === $target || $np === $targetPlain) {
                $exact = $entity;
                break;
            }
            if ($plain === null && ($np === $targetPlain || $n === $targetPlain)) {
                $plain = $entity;
            }
            if (! $hasForeignDigits && $starts === null && (str_starts_with($n, $target) || str_starts_with($np, $targetPlain))) {
                // Avoid "Hades" winning over "Hades II" when steam is Hades II (handled by exact/plain first).
                // When steam is Hades, "Hades II" startswith "hades" — do not treat as steam match.
                if ($n === $target || $np === $targetPlain || self::isPrefixOnlyFranchise($targetPlain, $np)) {
                    // skip longer franchise sibling as start match for shorter steam name
                } else {
                    $starts = $entity;
                }
            }
            if (! $hasForeignDigits && $contains === null && (str_contains($n, $target) || str_contains($np, $targetPlain))) {
                if (mb_strlen($targetPlain) >= 6) {
                    $contains = $entity;
                }
            }
            if ($orderedCoverage === null && self::hasOrderedTokenCoverage($targetPlain, $np)) {
                $orderedCoverage = $entity;
            }
        }

        return $exact ?? $plain ?? $starts ?? $contains ?? $orderedCoverage;
    }

    /**
     * Catalogs sometimes keep a former or expanded product name after Steam
     * renames an app (for example, an old subtitle around the current title).
     * Accept that alias only when every target token appears in order and the
     * catalog name introduces no foreign number/sequel marker.
     */
    private static function hasOrderedTokenCoverage(string $target, string $candidate): bool
    {
        $targetTokens = preg_split('/\s+/u', trim($target), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $candidateTokens = preg_split('/\s+/u', trim($candidate), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if (count($targetTokens) < 2 || $candidateTokens === []) {
            return false;
        }

        if (self::hasForeignDigitTokens($target, $candidate)) {
            return false;
        }

        $position = 0;
        foreach ($targetTokens as $targetToken) {
            $found = false;
            for ($i = $position, $count = count($candidateTokens); $i < $count; $i++) {
                if ($candidateTokens[$i] === $targetToken) {
                    $position = $i + 1;
                    $found = true;
                    break;
                }
            }
            if (! $found) {
                return false;
            }
        }

        return true;
    }

    private static function hasForeignDigitTokens(string $target, string $candidate): bool
    {
        $tokens = static fn (string $value): array => preg_split('/\s+/u', trim($value), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $digits = static fn (array $items): array => array_values(array_filter($items, static fn (string $token): bool => ctype_digit($token)));

        return array_values(array_diff($digits($tokens($candidate)), $digits($tokens($target)))) !== [];
    }

    /**
     * True when candidate is a longer title that only shares the steam name as a prefix
     * ("Hades II" vs steam "Hades") — not a match for the shorter game.
     */
    public static function isPrefixOnlyFranchise(string $steamPlain, string $candidatePlain): bool
    {
        if ($steamPlain === '' || $candidatePlain === '' || $candidatePlain === $steamPlain) {
            return false;
        }
        if (! str_starts_with($candidatePlain, $steamPlain)) {
            return false;
        }
        $rest = trim(mb_substr($candidatePlain, mb_strlen($steamPlain)));

        return $rest !== '' && (bool) preg_match('/^([ivxlcdm]+|\d+)\b/iu', $rest);
    }

    /**
     * Among lots for a free-text search, keep those belonging to the resolved category:
     * best-matching category name present in the lot title must be our category
     * (so "Hades II …" stays under Hades II, not under Hades).
     *
     * @param  list<string>  $categoryNames
     */
    public static function lotBelongsToCategory(string $lotTitle, string $categoryName, array $categoryNames): bool
    {
        $title = self::norm(MarketQuery::plain($lotTitle));
        if ($title === '') {
            return false;
        }

        $wanted = self::norm(MarketQuery::plain($categoryName));
        if ($wanted === '') {
            return false;
        }

        $best = null;
        $bestLen = 0;
        foreach ($categoryNames as $cat) {
            $c = self::norm(MarketQuery::plain($cat));
            if ($c === '' || mb_strlen($c) < 2) {
                continue;
            }
            if ($title === $c || str_starts_with($title, $c.' ') || str_contains($title, ' '.$c.' ') || str_starts_with($title, $c)) {
                if (mb_strlen($c) > $bestLen) {
                    $best = $c;
                    $bestLen = mb_strlen($c);
                }
            }
        }

        if ($best === null) {
            // No category token in title — do not guess.
            return false;
        }
        if ($best === $wanted) {
            return true;
        }
        // Sub-chips like «Hades ключи» still belong to game card «Hades».
        // But «Hades II …» must not fall under «Hades».
        if (str_starts_with($best, $wanted.' ')) {
            $rest = trim(mb_substr($best, mb_strlen($wanted)));

            return ! self::isPrefixOnlyFranchise($wanted, $best) && ! (bool) preg_match('/^([ivxlcdm]+|\d+)\b/iu', $rest);
        }

        return false;
    }

    public static function norm(string $s): string
    {
        // Marketplace cards often replace Latin diacritics with their ASCII
        // spelling (Ragnarök -> Ragnarok). They also mix Roman and Arabic
        // sequel numbers (GTA V -> GTA 5). Normalize those representation
        // differences before applying the existing sequel-safety checks.
        $s = mb_strtolower(trim(Str::ascii($s)));
        $roman = [
            'xx' => '20', 'xix' => '19', 'xviii' => '18', 'xvii' => '17',
            'xvi' => '16', 'xv' => '15', 'xiv' => '14', 'xiii' => '13',
            'xii' => '12', 'xi' => '11', 'x' => '10', 'ix' => '9',
            'viii' => '8', 'vii' => '7', 'vi' => '6', 'v' => '5',
            'iv' => '4', 'iii' => '3', 'ii' => '2', 'i' => '1',
        ];
        $s = preg_replace_callback(
            '/(?<![\p{L}\p{N}])('.implode('|', array_keys($roman)).')(?![\p{L}\p{N}])/iu',
            static fn (array $match): string => $roman[mb_strtolower($match[1])] ?? $match[1],
            $s
        ) ?? $s;
        $s = preg_replace('/\s+/u', ' ', $s) ?? $s;

        return $s;
    }
}
