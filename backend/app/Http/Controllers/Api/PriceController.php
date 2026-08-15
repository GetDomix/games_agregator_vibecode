<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Favorite;
use App\Models\Game;
use App\Models\PriceSnapshot;
use App\Models\SearchHistory;
use App\Models\User;
use App\Services\Catalog\AggregatorService;
use App\Services\Catalog\StoredPriceSearchService;
use App\Services\Pricing\GameRefreshRequestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class PriceController extends Controller
{
    public function __construct(
        private readonly AggregatorService $aggregator,
        private readonly StoredPriceSearchService $stored,
    ) {}

    public function search(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));
        if ($q === '') {
            return response()->json(['detail' => 'Пустой поисковый запрос'], 400);
        }
        $suggestionLimit = 20;
        $candidates = $this->stored->candidates($q, $suggestionLimit);
        $discovery = false;
        if ($candidates === [] || ($request->boolean('discover') && count($candidates) < $suggestionLimit)) {
            $discovered = $this->aggregator->searchCandidates($q, $suggestionLimit);
            $candidates = $this->mergeSearchCandidates($candidates, $discovered, $suggestionLimit);
            $discovery = true;
        }

        return response()->json(['query' => $q, 'candidates' => $candidates, 'meta' => ['discovery_used' => $discovery]]);
    }

    private function mergeSearchCandidates(array $stored, array $discovered, int $limit): array
    {
        $merged = [];
        foreach ($stored as $candidate) {
            $merged[(int) $candidate['appid']] = $candidate;
        }
        foreach ($discovered as $candidate) {
            $appid = (int) $candidate['appid'];
            if (! isset($merged[$appid])) {
                $merged[$appid] = $candidate;
                continue;
            }

            $known = $merged[$appid];
            // Store search owns the live price fields; the canonical catalog owns
            // durable release/type metadata and its best full-size artwork.
            $merged[$appid] = array_replace($known, $candidate, [
                'release_status' => $known['release_status'] ?? $candidate['release_status'] ?? null,
                'candidate_kind' => $known['candidate_kind'] ?? $candidate['candidate_kind'] ?? 'game',
                'header_image' => $known['header_image'] ?? $candidate['header_image'] ?? null,
            ]);
        }

        return array_slice(array_values($merged), 0, $limit);
    }

    public function prices(Request $request, GameRefreshRequestService $refresh): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));
        if ($q === '') {
            return response()->json(['detail' => 'Пустой поисковый запрос'], 400);
        }
        $appid = $request->query('appid');
        $appid = $appid !== null && $appid !== '' ? (int) $appid : null;
        if ($appid !== null && $appid < 1) {
            return response()->json(['detail' => 'Некорректный appid'], 422);
        }

        $user = Auth::guard('sanctum')->user();
        $force = $request->boolean('force');

        $game = $appid ? Game::query()->where('steam_appid', $appid)->first() : null;
        $queuedOnThisRequest = false;
        if (! $game && $appid) {
            $game = $refresh->requestUnknown($appid, $q);
            $queuedOnThisRequest = true;
        }
        if (! $game && ! $appid) {
            // A price request is intentionally exact: never turn an ambiguous name into
            // a refresh, history row, or a silently selected game. Discovery remains /search.
            $matches = $this->stored->candidates($q);
            $exact = $this->stored->exactCandidates($q);
            if (count($exact) === 1) {
                $game = Game::query()->where('steam_appid', $exact[0]['appid'])->first();
            }
            if (! $game) {
                return response()->json($this->ambiguousResult($q, $exact !== [] ? $exact : $matches));
            }
        }

        // A browser request must never wait for three external stores. Manual
        // refresh only places work on the same background queue as first search.
        if ($force && ! $queuedOnThisRequest) {
            $refresh->request($game, \App\Models\GameSourceState::SOURCES);
        }

        $result = $this->stored->result($game, $q);

        if ($user) {
            if (! $request->boolean('background')) {
                $result['saved_to_history'] = $this->saveHistory($user, $result);
            }
            $steamAppid = $result['steam']['appid'] ?? $appid;
            $result['is_favorite'] = $steamAppid
                ? Favorite::query()->where('user_id', $user->id)->where('appid', $steamAppid)->exists()
                : false;
        }

        return response()->json($result);
    }

    private function saveHistory(User $user, array $result): bool
    {
        try {
            $platiMin = $this->minFromMarket($result['plati'] ?? []);
            $ggselMin = $this->minFromMarket($result['ggsel'] ?? []);
            $steam = $result['steam'] ?? null;
            SearchHistory::create([
                'user_id' => $user->id,
                'query' => $result['query'],
                'appid' => $steam['appid'] ?? null,
                'game_name' => $steam['name'] ?? $result['query'],
                'header_image' => $steam['header_image'] ?? null,
                'steam_price_rub' => $steam['price_rub'] ?? null,
                'plati_min_rub' => $platiMin,
                'ggsel_min_rub' => $ggselMin,
                'meta' => ['deal' => $result['deal'] ?? null],
            ]);
            $markets = array_filter([$platiMin, $ggselMin], fn ($v) => $v !== null);
            PriceSnapshot::create([
                'user_id' => $user->id,
                'appid' => $steam['appid'] ?? null,
                'steam_price_rub' => $steam['price_rub'] ?? null,
                'market_min_rub' => $markets ? min($markets) : null,
                'source_query' => $result['query'],
                'payload' => ['deal' => $result['deal'] ?? null],
            ]);
            if (! empty($steam['appid']) && isset($steam['price_rub'])) {
                Favorite::query()
                    ->where('user_id', $user->id)
                    ->where('appid', $steam['appid'])
                    ->update(['last_steam_price_rub' => $steam['price_rub']]);
            }
            // soft cap 500
            $count = SearchHistory::query()->where('user_id', $user->id)->count();
            if ($count > 500) {
                $ids = SearchHistory::query()
                    ->where('user_id', $user->id)
                    ->orderBy('created_at')
                    ->limit($count - 500)
                    ->pluck('id');
                SearchHistory::query()->whereIn('id', $ids)->delete();
            }

            return true;
        } catch (\Throwable $e) {
            report($e);

            return false;
        }
    }

    private function minFromMarket(array $stats): ?float
    {
        $mins = [];
        foreach ($stats['by_kind'] ?? [] as $k) {
            if (isset($k['min_price']) && is_numeric($k['min_price'])) {
                $mins[] = (float) $k['min_price'];
            }
        }

        return $mins === [] ? null : min($mins);
    }

    private function ambiguousResult(string $query, array $candidates): array
    {
        $emptyMarket = fn (string $marketplace, string $label) => [
            'marketplace' => $marketplace, 'label' => $label, 'total_offers' => 0,
            'scanned_offers' => 0, 'by_kind' => [], 'error' => null,
        ];

        return [
            'query' => $query,
            'steam' => null,
            'candidates' => $candidates,
            'plati' => $emptyMarket('plati', 'Plati.Market'),
            'ggsel' => $emptyMarket('ggsel', 'GGsel'),
            'warnings' => [],
            'saved_to_history' => false,
            'is_favorite' => false,
            'deal' => null,
            'refreshing' => false,
            'freshness' => [],
        ];
    }
}
