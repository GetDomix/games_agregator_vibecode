<?php

namespace App\Services;

/**
 * Drop marketplace noise (wrong game, pure OST packs as primary match, empty titles).
 *
 * Две защиты от «похожих, но других игр»:
 *  1. Числовые токены (год/номер): "1998" — сильный дискриминатор; чужой год сильно штрафует.
 *  2. Биграммы смежности: если общее длинное имя в титуле разорвано другими словами
 *     ("The Backrooms Footage Horror" вместо "The Backrooms 1998 Found Footage..."),
 *     это почти наверняка другая игра — отсекаем.
 */
class OfferRelevance
{
    /** @param list<array<string, mixed>> $offers @return list<array<string, mixed>> */
    public static function filter(array $offers, string $gameName): array
    {
        $tokens = self::tokens($gameName);
        if ($tokens === [] || $offers === []) {
            return $offers;
        }

        $kept = [];
        $threshold = count($tokens) >= 3 ? 0.5 : 0.28;
        foreach ($offers as $o) {
            $title = (string) ($o['title'] ?? '');
            if ($title === '') {
                continue;
            }
            $score = self::score($title, $tokens, $gameName);
            if ($score < $threshold) {
                continue;
            }
            $o['_relevance'] = round($score, 3);
            $kept[] = $o;
        }

        return $kept;
    }

    /** @param list<string> $gameTokens */
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
                if ($tt === $gt) {
                    $hit++;
                    break;
                }
                // allow plural/declension drift only (length diff <= 1, prefix-aligned)
                if (abs(mb_strlen($tt) - mb_strlen($gt)) <= 1 && (str_starts_with($tt, $gt) || str_starts_with($gt, $tt))) {
                    $hit++;
                    break;
                }
            }
        }

        // Биграммы смежности: тайтл заявляет полное имя (все токены на месте), но они
        // разорваны — вероятно, другая игра с похожим названием. Частичные совпадения
        // не режем: продавцы часто сокращают («Silksong аккаунт» для Hollow Knight: Silksong).
        if ($hit === count($gameTokens) && self::adjacencyScore($gameName, $t) < 0.5) {
            return 0.05;
        }

        $base = $hit / max(1, count($gameTokens));

        // numbers like "1998" are strong discriminators between similarly named games
        $gameNumbers = array_values(array_filter($gameTokens, fn ($tok) => (bool) preg_match('/^\d+$/', $tok)));
        $titleNumbers = array_values(array_filter($titleTokens, fn ($tok) => (bool) preg_match('/^\d+$/', $tok)));
        if ($gameNumbers !== []) {
            $shared = array_intersect($gameNumbers, $titleNumbers);
            if ($shared !== []) {
                $base = max($base, 0.55);
            } elseif ($titleNumbers !== []) {
                // конфликтующие числа = другая нумерованная игра
                $base *= 0.25;
            }
            // тайтл вовсе без чисел не штрафуем: продавцы часто опускают год
        } elseif ($titleNumbers !== []) {
            preg_match_all('/\d+/u', $gameName, $m);
            $foreign = array_diff($titleNumbers, $m[0] ?? []);
            if ($foreign !== []) {
                $hasYear = false;
                foreach ($foreign as $f) {
                    $n = (int) $f;
                    if ($n >= 1900 && $n <= 2035) {
                        $hasYear = true;
                        break;
                    }
                }
                // foreign year almost certainly means another game (e.g. "1998")
                $base *= $hasYear ? 0.2 : 0.4;
            }
        }

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

        // Он-топик покрытие: все «чужие» токены тайтла — рыночный шум (steam, key,
        // аккаунт…). Продавец просто сократил имя — поднимаем score, чтобы сокращения
        // проходили порог filter() для игр с 3+ токенами.
        $noise = ['steam', 'key', 'ключ', 'аккаунт', 'account', 'gift', 'гифт', 'подарок', 'подарочный', 'ru', 'рус', 'рф', 'россия', 'russia', 'region', 'регион', 'global', 'глобал', 'pc', 'пк', 'random', 'рандом', 'sale', 'скидка', 'buy', 'покупка', 'активация', 'activation', 'версия', 'издание', 'снг', 'eu', 'европа', 'usa', 'сша', 'турция', 'tr'];
        $uncovered = [];
        foreach ($titleTokens as $tt) {
            $coveredByGame = false;
            foreach ($gameTokens as $gt) {
                if ($tt === $gt || (abs(mb_strlen($tt) - mb_strlen($gt)) <= 1 && (str_starts_with($tt, $gt) || str_starts_with($gt, $tt)))) {
                    $coveredByGame = true;
                    break;
                }
            }
            if (! $coveredByGame && ! in_array($tt, $noise, true)) {
                $uncovered[] = $tt;
            }
        }
        if ($uncovered === []) {
            $base = max($base, 0.55);
        }

        return min(1.0, $base);
    }

    /**
     * Доля биграмм нормализованного имени игры, найденных в титуле подряд
     * (разрыв максимум в 1 слово — шум маркетплейсов).
     */
    private static function adjacencyScore(string $gameName, string $title): float
    {
        $qTokens = self::tokens($gameName);
        if (count($qTokens) < 3) {
            // короткие имена: защита от ложных срабатываний дороже, чем от пропуска
            return 1.0;
        }

        $bigrams = [];
        for ($i = 0, $n = count($qTokens); $i < $n - 1; $i++) {
            $bigrams[] = [$qTokens[$i], $qTokens[$i + 1]];
        }

        $tTokens = self::tokens($title);
        $found = 0;
        foreach ($bigrams as [$a, $b]) {
            for ($i = 0, $n = count($tTokens); $i < $n - 1; $i++) {
                if ($tTokens[$i] === $a && ($tTokens[$i + 1] === $b || ($i + 2 < $n && $tTokens[$i + 2] === $b))) {
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
        $n = mb_strtolower($name);
        $n = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $n) ?? $n;
        $n = trim(preg_replace('/\s+/u', ' ', $n) ?? $n);
        $parts = preg_split('/\s+/u', $n) ?: [];
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
}
