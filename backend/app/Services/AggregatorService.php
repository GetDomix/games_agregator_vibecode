<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class AggregatorService
{
    public function __construct(
        private readonly SteamService $steam,
        private readonly PlatiService $plati,
        private readonly GgselService $ggsel,
    ) {}

    public function searchCandidates(string $query): array
    {
        return $this->steam->search($query);
    }

    public function aggregate(string $query, ?int $appid = null): array
    {
        $query = trim($query);
        if ($query === '') {
            throw new \InvalidArgumentException('Пустой поисковый запрос');
        }

        $ttl = max(60, (int) config('gpa.search_cache_ttl', 900));
        $cacheKey = 'gpa:prices:v2:'.sha1(mb_strtolower($query).'|'.($appid ?? 0));

        $run = function () use ($cacheKey, $ttl, $query, $appid) {
            $cached = Cache::get($cacheKey);
            if (is_array($cached)) {
                $cached['cache'] = 'hit';

                return $cached;
            }

            $fresh = $this->aggregateFresh($query, $appid);
            $fresh['cache'] = 'miss';
            Cache::put($cacheKey, $fresh, $ttl);

            return $fresh;
        };

        // Singleflight when atomic locks are available (file/redis/database)
        try {
            return Cache::lock('lock:'.$cacheKey, 45)->block(40, $run);
        } catch (\Throwable) {
            return $run();
        }
    }

    private function aggregateFresh(string $query, ?int $appid = null): array
    {
        $candidates = [];
        $warnings = [];
        $steam = null;
        $selectedName = $query;

        try {
            $candidates = $this->steam->search($query);
        } catch (\Throwable $e) {
            Log::warning('steam.search failed', ['e' => $e->getMessage()]);
            $warnings[] = 'Steam search: временный сбой';
        }

        try {
            if ($appid !== null) {
                $match = null;
                foreach ($candidates as $c) {
                    if ((int) $c['appid'] === $appid) {
                        $match = $c;
                        break;
                    }
                }
                $steam = $this->steam->details($appid, $match['name'] ?? $query);
                $selectedName = $steam['name'];
            } else {
                $best = $this->steam->pickBest($candidates, $query);
                if ($best) {
                    $steam = $this->steam->details((int) $best['appid'], $best['name']);
                    $selectedName = $steam['name'];
                } else {
                    $warnings[] = 'Steam не нашёл игру по запросу. Ищем на маркетплейсах по введённому названию.';
                }
            }
        } catch (\Throwable $e) {
            Log::warning('steam.details failed', ['e' => $e->getMessage()]);
            $warnings[] = 'Steam details: временный сбой';
        }

        $marketQuery = $selectedName ?: $query;
        $platiOffers = [];
        $platiTotal = 0;
        $platiErr = null;
        $ggselOffers = [];
        $ggselTotal = 0;
        $ggselErr = null;

        try {
            [$platiOffers, $platiTotal, $platiErr] = $this->plati->search($marketQuery);
        } catch (\Throwable $e) {
            $platiErr = $e->getMessage();
        }
        try {
            [$ggselOffers, $ggselTotal, $ggselErr] = $this->ggsel->search($marketQuery);
        } catch (\Throwable $e) {
            $ggselErr = $e->getMessage();
        }

        $beforePlati = count($platiOffers);
        $beforeGgsel = count($ggselOffers);
        $platiOffers = OfferRelevance::filter($platiOffers, $marketQuery);
        $ggselOffers = OfferRelevance::filter($ggselOffers, $marketQuery);
        $dropped = ($beforePlati - count($platiOffers)) + ($beforeGgsel - count($ggselOffers));
        if ($dropped > 0) {
            $warnings[] = "Отфильтровано слаборелевантных лотов: {$dropped}";
        }

        if ($platiErr) {
            $warnings[] = 'Plati: '.$platiErr;
        }
        if ($ggselErr) {
            $warnings[] = 'GGsel: '.$ggselErr;
        }
        if ($steam && empty($steam['available_in_ru']) && ! empty($steam['note'])) {
            $warnings[] = $steam['note'];
        }

        $platiStats = $this->marketplaceStats('plati', 'Plati.Market', $platiOffers, $platiTotal, $platiErr);
        $ggselStats = $this->marketplaceStats('ggsel', 'GGsel', $ggselOffers, $ggselTotal, $ggselErr);

        $steamPrice = ($steam && empty($steam['is_free'])) ? $steam['price_rub'] : null;
        $deal = DealScoreService::compute($steamPrice, $platiStats, $ggselStats);

        return [
            'query' => $query,
            'steam' => $steam,
            'candidates' => $candidates,
            'plati' => $platiStats,
            'ggsel' => $ggselStats,
            'warnings' => $warnings,
            'saved_to_history' => false,
            'is_favorite' => false,
            'deal' => $deal,
            'quota' => null,
        ];
    }

    private function marketplaceStats(string $id, string $label, array $offers, int $total, ?string $error): array
    {
        return [
            'marketplace' => $id,
            'label' => $label,
            'total_offers' => $total ?: count($offers),
            'scanned_offers' => count($offers),
            'by_kind' => $error ? [] : $this->aggregateByKind($offers),
            'error' => $error,
        ];
    }

    private function aggregateByKind(array $offers): array
    {
        $order = ['key', 'gift', 'account', 'rent', 'other'];
        $grouped = [];
        foreach ($offers as $o) {
            $kind = $o['kind'] ?? 'other';
            $grouped[$kind][] = $o;
        }
        $stats = [];
        foreach ($order as $kind) {
            $bucket = $grouped[$kind] ?? [];
            if ($bucket === []) {
                continue;
            }
            $prices = array_column($bucket, 'price_rub');
            $cheapest = $bucket[0];
            $popular = $bucket[0];
            foreach ($bucket as $o) {
                if ($o['price_rub'] < $cheapest['price_rub']) {
                    $cheapest = $o;
                }
                if (($o['sales'] ?? 0) > ($popular['sales'] ?? 0)
                    || (($o['sales'] ?? 0) === ($popular['sales'] ?? 0) && $o['price_rub'] < $popular['price_rub'])) {
                    $popular = $o;
                }
            }
            $stats[] = [
                'kind' => $kind,
                'label' => Classifier::label($kind),
                'count' => count($bucket),
                'min_price' => round(min($prices), 2),
                'avg_price' => round(array_sum($prices) / count($prices), 2),
                'popular' => $this->stripInternal($popular),
                'cheapest' => $this->stripInternal($cheapest),
            ];
        }

        return $stats;
    }

    private function stripInternal(array $o): array
    {
        unset($o['_relevance']);

        return $o;
    }
}
