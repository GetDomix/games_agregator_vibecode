<?php

namespace App\Services\Catalog;

use App\Services\Pricing\GgselService;
use App\Services\Pricing\PlatiService;
use App\Services\Pricing\SteamService;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class AggregatorService
{
    public function __construct(
        private readonly SteamService $steam,
        private readonly PlatiService $plati,
        private readonly GgselService $ggsel,
    ) {}

    public function searchCandidates(string $query, int $limit = 8): array
    {
        return $this->steam->search($query, $limit);
    }

    public function aggregate(string $query, ?int $appid = null): array
    {
        $query = trim($query);
        if ($query === '') {
            throw new \InvalidArgumentException('Пустой поисковый запрос');
        }

        $ttl = max(60, (int) config('gpa.search_cache_ttl', 900));
        // v5: GGsel resolves a catalog card and fetches by digi_catalog id.
        $cacheKey = 'gpa:prices:v5:'.sha1(mb_strtolower($query).'|'.($appid ?? 0));

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

        // Markets follow the Steam-resolved title as the game identity.
        $marketAnchor = trim((string) ($selectedName ?: $query));

        $platiOffers = [];
        $platiErr = null;
        $ggselOffers = [];
        $ggselErr = null;
        $platiGame = null;

        try {
            $platiGame = $this->plati->resolveGame($marketAnchor);
            if ($platiGame !== null) {
                [$platiOffers, , $platiErr] = $this->plati->productsForGame((int) $platiGame['id_cb'], (string) $platiGame['name']);
            }
        } catch (\Throwable $e) {
            $platiErr = $e->getMessage();
        }
        try {
            [$ggselOffers, , $ggselErr] = $this->ggsel->search($marketAnchor);
        } catch (\Throwable $e) {
            $ggselErr = $e->getMessage();
        }

        // Game cards scope identity, but marketplaces may mix Steam and console
        // products inside one card. Keep generic/Steam lots and drop explicit
        // Xbox, PlayStation, Nintendo and competing PC-store offers.
        $platiOffers = SteamOfferEligibility::filter($platiOffers, $marketAnchor);
        $ggselOffers = SteamOfferEligibility::filter($ggselOffers, $marketAnchor);

        if ($platiErr) {
            $warnings[] = 'Plati: '.$platiErr;
        } elseif ($platiOffers === [] && $platiGame === null) {
            $warnings[] = 'Plati: в каталоге площадки нет карточки «'.$marketAnchor.'» — свободную выдачу по названиям лотов не используем.';
        }
        if ($ggselErr) {
            $warnings[] = 'GGsel: '.$ggselErr;
        } elseif ($ggselOffers === []) {
            $warnings[] = 'GGsel: нет совпадающей категории в каталоге «'.$marketAnchor.'» или лотов в ней.';
        }
        if ($steam && empty($steam['available_in_ru']) && ! empty($steam['note'])) {
            $warnings[] = $steam['note'];
        }

        $platiStats = $this->marketplaceStats('plati', 'Plati.Market', $platiOffers, $platiErr);
        $ggselStats = $this->marketplaceStats('ggsel', 'GGsel', $ggselOffers, $ggselErr);

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
            'meta' => [
                'market_anchor' => $marketAnchor,
                'plati_game_id' => $platiGame['id_cb'] ?? null,
                'plati_game_name' => $platiGame['name'] ?? null,
            ],
        ];
    }

    private function marketplaceStats(string $id, string $label, array $offers, ?string $error): array
    {
        $count = count($offers);

        return [
            'marketplace' => $id,
            'label' => $label,
            'total_offers' => $count,
            'scanned_offers' => $count,
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
            $popular = null;
            foreach ($bucket as $o) {
                if ($o['price_rub'] < $cheapest['price_rub']) {
                    $cheapest = $o;
                }
                if (! isset($o['sales']) || ! is_numeric($o['sales'])) {
                    continue;
                }
                if ($popular === null
                    || (int) $o['sales'] > (int) $popular['sales']
                    || ((int) $o['sales'] === (int) $popular['sales'] && $o['price_rub'] < $popular['price_rub'])) {
                    $popular = $o;
                }
            }
            $stats[] = [
                'kind' => $kind,
                'label' => Classifier::label($kind),
                'count' => count($bucket),
                'min_price' => round(min($prices), 2),
                'avg_price' => round(array_sum($prices) / count($prices), 2),
                'popular' => $popular === null ? null : $this->stripInternal($popular),
                'cheapest' => $this->stripInternal($cheapest),
            ];
        }

        return $stats;
    }

    private function stripInternal(array $o): array
    {
        unset($o['_relevance'], $o['external_id']);

        return $o;
    }
}
