<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Favorite;
use App\Models\Game;
use App\Models\PriceSnapshot;
use App\Models\SearchHistory;
use App\Models\User;
use App\Services\AggregatorService;
use App\Services\GamePriceRefreshService;
use App\Services\GameRefreshRequestService;
use App\Services\StoredPriceSearchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class PriceController extends Controller
{
    public function __construct(
        private readonly AggregatorService $aggregator,
        private readonly StoredPriceSearchService $stored,
        private readonly GamePriceRefreshService $refreshService,
    ) {}

    public function search(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));
        if ($q === '') {
            return response()->json(['detail' => 'Пустой поисковый запрос'], 400);
        }
        $candidates = $this->stored->candidates($q);
        $discovery = false;
        if ($candidates === []) {
            $candidates = $this->aggregator->searchCandidates($q);
            $discovery = true;
        }

        return response()->json(['query' => $q, 'candidates' => $candidates, 'meta' => ['discovery_used' => $discovery]]);
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
        if (! $game && $appid) {
            $game = $refresh->requestUnknown($appid, $q);
        }
        if (! $game) {
            $candidate = $this->stored->candidates($q, 1)[0] ?? null;
            if ($candidate) {
                $game = Game::query()->where('steam_appid', $candidate['appid'])->first();
                if (! $game && $force) {
                    $game = Game::query()->firstOrCreate(
                        ['steam_appid' => (int) $candidate['appid']],
                        [
                            'name' => mb_substr(trim($candidate['name'] ?? '') ?: "Steam app {$candidate['appid']}", 0, 200),
                            'header_image' => $candidate['header_image'] ?? null,
                        ]
                    );
                }
            }
        }
        if (! $game) {
            // Нет локальной игры: отдаём совпадения, чтобы пользователь выбрал из списка под поиском.
            $matches = $this->stored->candidates($q);
            if ($matches === []) {
                $matches = array_slice($this->aggregator->searchCandidates($q), 0, 8);
            }
            if ($matches !== [] && $force) {
                $top = $matches[0];
                $game = Game::query()->firstOrCreate(
                    ['steam_appid' => (int) $top['appid']],
                    [
                        'name' => mb_substr(trim($top['name'] ?? '') ?: "Steam app {$top['appid']}", 0, 200),
                        'header_image' => $top['header_image'] ?? null,
                    ]
                );
            }
            if (! $game) {
                return response()->json(['query' => $q, 'steam' => null, 'candidates' => $matches, 'plati' => ['marketplace' => 'plati', 'label' => 'Plati.Market', 'total_offers' => 0, 'scanned_offers' => 0, 'by_kind' => []], 'ggsel' => ['marketplace' => 'ggsel', 'label' => 'GGsel', 'total_offers' => 0, 'scanned_offers' => 0, 'by_kind' => []], 'warnings' => [], 'refreshing' => false]);
            }
        }

        if ($force) {
            try {
                $this->refreshService->refresh($game, 'steam');
            } catch (\Throwable) {
            }
            $game->refresh();
        }

        $result = $this->stored->result($game, $q);

        if ($user) {
            $result['saved_to_history'] = $this->saveHistory($user, $result);
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
}
