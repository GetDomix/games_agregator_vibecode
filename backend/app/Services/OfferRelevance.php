<?php

namespace App\Services;

/**
 * Drop marketplace noise (wrong game, pure OST packs as primary match, empty titles).
 */
class OfferRelevance
{
    public static function filter(array $offers, string $gameName): array
    {
        $tokens = self::tokens($gameName);
        if ($tokens === [] || $offers === []) {
            return $offers;
        }

        $kept = [];
        foreach ($offers as $o) {
            $title = (string) ($o['title'] ?? '');
            if ($title === '') {
                continue;
            }
            $score = self::score($title, $tokens, $gameName);
            if ($score < 0.28) {
                continue;
            }
            $o['_relevance'] = round($score, 3);
            $kept[] = $o;
        }

        // If filter wiped everything, fall back to original (better noisy than empty)
        return $kept === [] ? $offers : $kept;
    }

    /** @return list<string> */
    public static function tokens(string $name): array
    {
        $n = mb_strtolower($name);
        $n = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $n) ?? $n;
        $parts = preg_split('/\s+/u', trim($n)) ?: [];
        $stop = ['the', 'a', 'an', 'and', 'or', 'of', 'edition', 'game', 'и', 'для', 'на'];
        $out = [];
        foreach ($parts as $p) {
            if (mb_strlen($p) < 2 || in_array($p, $stop, true)) {
                continue;
            }
            $out[] = $p;
        }

        return array_values(array_unique($out));
    }

    public static function score(string $title, array $gameTokens, string $gameName): float
    {
        $t = mb_strtolower($title);
        $titleTokens = self::tokens($title);
        if ($titleTokens === []) {
            return 0.0;
        }

        $hit = 0;
        foreach ($gameTokens as $gt) {
            foreach ($titleTokens as $tt) {
                if ($tt === $gt || str_contains($tt, $gt) || str_contains($gt, $tt)) {
                    $hit++;
                    break;
                }
            }
        }
        $base = $hit / max(1, count($gameTokens));

        // penalize pure soundtrack / wallpaper packs when game name is not OST-like
        $gameIsOst = (bool) preg_match('/\b(ost|soundtrack|саундтрек)\b/iu', $gameName);
        if (! $gameIsOst && preg_match('/\b(ost|soundtrack|саундтрек|wallpaper|обои|cursor)\b/iu', $t)) {
            $base *= 0.35;
        }

        // boost if full game name substring
        $gn = mb_strtolower(preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $gameName) ?? $gameName);
        $gn = trim(preg_replace('/\s+/u', ' ', $gn) ?? $gn);
        if ($gn !== '' && str_contains($t, $gn)) {
            $base = max($base, 0.85);
        }

        return min(1.0, $base);
    }
}
