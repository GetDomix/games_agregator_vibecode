<?php

namespace App\Services;

class DealScoreService
{
    public static function compute(?float $steamPrice, array $plati, array $ggsel): array
    {
        $platiMin = self::marketMin($plati);
        $ggselMin = self::marketMin($ggsel);
        $candidates = [];
        if ($platiMin !== null) {
            $candidates[] = ['plati', $platiMin];
        }
        if ($ggselMin !== null) {
            $candidates[] = ['ggsel', $ggselMin];
        }
        $marketSource = null;
        $marketMin = null;
        if ($candidates !== []) {
            usort($candidates, fn ($a, $b) => $a[1] <=> $b[1]);
            [$marketSource, $marketMin] = $candidates[0];
        }

        if ($steamPrice === null || $steamPrice <= 0 || $marketMin === null) {
            return [
                'steam_price_rub' => $steamPrice,
                'market_min_rub' => $marketMin,
                'market_source' => $marketSource,
                'savings_rub' => null,
                'savings_percent' => null,
                'score' => 0,
                'label' => $marketMin === null ? 'нет сравнения' : 'Steam н/д',
                'is_better' => false,
            ];
        }

        $savingsRub = round($steamPrice - $marketMin, 2);
        $savingsPercent = round(($savingsRub / $steamPrice) * 100, 1);
        $isBetter = $savingsRub > 0;

        if (! $isBetter) {
            $score = max(0, min(15, (int) (20 + $savingsPercent)));
            $label = $savingsRub < -1 ? 'дороже Steam' : '≈ Steam';
        } elseif ($savingsPercent >= 40) {
            $score = 95;
            $label = 'огонь-сделка';
        } elseif ($savingsPercent >= 25) {
            $score = 80;
            $label = 'отличная цена';
        } elseif ($savingsPercent >= 12) {
            $score = 65;
            $label = 'выгодно';
        } elseif ($savingsPercent >= 5) {
            $score = 45;
            $label = 'чуть дешевле';
        } else {
            $score = 25;
            $label = 'почти как Steam';
        }

        if ($isBetter) {
            $score = max($score, min(100, (int) round($savingsPercent * 2.2)));
        }

        return [
            'steam_price_rub' => $steamPrice,
            'market_min_rub' => $marketMin,
            'market_source' => $marketSource,
            'savings_rub' => $savingsRub,
            'savings_percent' => $savingsPercent,
            'score' => max(0, min(100, $score)),
            'label' => $label,
            'is_better' => $isBetter,
        ];
    }

    private static function marketMin(array $stats): ?float
    {
        if (! empty($stats['error'])) {
            return null;
        }
        $mins = [];
        foreach ($stats['by_kind'] ?? [] as $k) {
            if (isset($k['min_price']) && is_numeric($k['min_price'])) {
                $mins[] = (float) $k['min_price'];
            }
        }

        return $mins === [] ? null : min($mins);
    }
}
