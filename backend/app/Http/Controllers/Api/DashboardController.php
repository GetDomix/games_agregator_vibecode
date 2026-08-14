<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Favorite;
use App\Models\FavoriteAlert;
use App\Models\SearchHistory;
use App\Services\Alerts\SuggestedAlertTargetService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function me(Request $request, SuggestedAlertTargetService $suggestions): JsonResponse
    {
        $user = $request->user();
        $recent = SearchHistory::query()
            ->where('user_id', $user->id)
            ->orderByDesc('created_at')
            ->limit(8)
            ->get()
            ->map->toApiArray()
            ->values();
        $favs = Favorite::query()
            ->where('user_id', $user->id)
            ->orderByDesc('updated_at')
            ->limit(12)
            ->with(['alert.scopes', 'game.sourceStates'])
            ->get();
        $suggestions->attach($favs);
        $favItems = $favs->map->toApiArray()->values();
        $favCount = Favorite::query()->where('user_id', $user->id)->count();
        $weekAgo = now()->subDays(7);
        $searchesTotal = SearchHistory::query()->where('user_id', $user->id)->count();
        $searchesWeek = SearchHistory::query()->where('user_id', $user->id)->where('created_at', '>=', $weekAgo)->count();
        $alertItems = FavoriteAlert::query()
            ->whereHas('favorite', fn ($query) => $query->where('user_id', $user->id))
            ->with(['favorite', 'event'])
            ->latest('updated_at')
            ->get();
        $priceHits = $alertItems
            ->filter(fn (FavoriteAlert $alert) => $alert->status === 'triggered' && $alert->event !== null)
            ->map(function (FavoriteAlert $alert) {
                $favorite = $alert->favorite;
                $event = $alert->event;

                return [
                    'id' => $favorite->id,
                    'appid' => (int) $favorite->appid,
                    'game_name' => $favorite->game_name,
                    'header_image' => $favorite->header_image,
                    'condition_type' => $alert->condition_type,
                    'target_value' => $alert->target_value,
                    'target_price_rub' => $alert->condition_type === 'target_price' ? $alert->target_value : null,
                    'hit_price_rub' => $event->offer_price_rub,
                    'hit_source' => $event->source,
                    'hit_offer_kind' => $event->offer_kind,
                ];
            })
            ->values();
        $alerts = $alertItems->count();

        $ctas = [];
        if ($favCount === 0) {
            $ctas[] = 'Добавь игру в избранное и настрой ценовой сигнал — вернёмся, когда он сработает.';
        } elseif ($favCount < 3) {
            $ctas[] = 'Ещё '.(3 - $favCount).' в избранном — и кабинет заработает на полную.';
        }
        if ($priceHits->isNotEmpty()) {
            $ctas[] = $priceHits->count().' ценовых сигналов уже сработало — загляни в «Сигналы».';
        }
        if ($ctas === []) {
            $ctas[] = 'Сравни цены перед покупкой — Steam, Plati и GGsel в одном окне.';
        }

        return response()->json([
            'user' => $user->toPublicArray(),
            'recent_history' => $recent,
            'favorites_preview' => $favItems,
            'favorites_count' => $favCount,
            'searches_total' => $searchesTotal,
            'searches_this_week' => $searchesWeek,
            'alerts_count' => $alerts,
            'price_hits' => $priceHits,
            'ctas' => $ctas,
        ]);
    }

    public function popular(Request $request): JsonResponse
    {
        $limit = min(max((int) $request->query('limit', 8), 1), 30);
        $since = now()->subDays(14);

        // Известные игры: строки истории с appid — группируем по appid.
        $byAppid = SearchHistory::query()
            ->selectRaw('appid, count(*) as cnt, max(game_name) as game_name, max(header_image) as header_image, max(query) as query')
            ->where('created_at', '>=', $since)
            ->whereNotNull('appid')
            ->groupBy('appid')
            ->get()
            ->map(function ($r) {
                $appid = (int) $r->appid;

                return [
                    'query' => $r->query,
                    'count' => (int) $r->cnt,
                    'appid' => $appid,
                    'game_name' => $r->game_name,
                    'header_image' => $r->header_image ?: "https://cdn.cloudflare.steamstatic.com/steam/apps/{$appid}/header.jpg",
                ];
            });

        // Свободные запросы без appid — только популярные (>= 3 поисков).
        $byQuery = SearchHistory::query()
            ->selectRaw('LOWER(query) as query, count(*) as cnt, max(appid) as appid, max(game_name) as game_name, max(header_image) as header_image')
            ->where('created_at', '>=', $since)
            ->whereNull('appid')
            ->groupByRaw('LOWER(query)')
            ->havingRaw('count(*) >= 3')
            ->get()
            ->map(function ($r) {
                $appid = $r->appid ? (int) $r->appid : null;

                return [
                    'query' => $r->query,
                    'count' => (int) $r->cnt,
                    'appid' => $appid,
                    'game_name' => $r->game_name,
                    'header_image' => $r->header_image ?: ($appid ? "https://cdn.cloudflare.steamstatic.com/steam/apps/{$appid}/header.jpg" : null),
                ];
            });

        $items = $byAppid->concat($byQuery)
            ->sortBy([
                fn ($a, $b) => $b['count'] <=> $a['count'],
                fn ($a, $b) => ((bool) ($b['header_image'] ?? null)) <=> ((bool) ($a['header_image'] ?? null)),
            ])
            ->take($limit)
            ->values();

        if ($items->count() < 4) {
            return response()->json(['items' => array_slice($this->curatedPopular(), 0, $limit), 'source' => 'curated']);
        }

        return response()->json(['items' => $items, 'source' => 'community']);
    }

    /**
     * Курируемый fallback — реально популярные игры с настоящими Steam appid.
     *
     * @return array<int, array{query: string, count: int, appid: int, game_name: string, header_image: string}>
     */
    private function curatedPopular(): array
    {
        $games = [
            ['appid' => 1145360, 'name' => 'Hades'],
            ['appid' => 1091500, 'name' => 'Cyberpunk 2077'],
            ['appid' => 1245620, 'name' => 'Elden Ring'],
            ['appid' => 1086940, 'name' => "Baldur's Gate 3"],
            ['appid' => 730, 'name' => 'Counter-Strike 2'],
            ['appid' => 292030, 'name' => 'The Witcher 3: Wild Hunt'],
            ['appid' => 1174180, 'name' => 'Red Dead Redemption 2'],
            ['appid' => 570, 'name' => 'Dota 2'],
        ];

        return array_map(fn ($g) => [
            'query' => $g['name'],
            'count' => 0,
            'appid' => $g['appid'],
            'game_name' => $g['name'],
            'header_image' => "https://cdn.cloudflare.steamstatic.com/steam/apps/{$g['appid']}/header.jpg",
        ], $games);
    }
}
