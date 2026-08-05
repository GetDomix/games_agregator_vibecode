<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Favorite;
use App\Models\SearchHistory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function me(Request $request): JsonResponse
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
            ->get();
        $favItems = $favs->map->toApiArray()->values();
        $favCount = Favorite::query()->where('user_id', $user->id)->count();
        $weekAgo = now()->subDays(7);
        $searchesTotal = SearchHistory::query()->where('user_id', $user->id)->count();
        $searchesWeek = SearchHistory::query()->where('user_id', $user->id)->where('created_at', '>=', $weekAgo)->count();
        $priceHits = $favItems->filter(fn ($i) => $i['price_below_target'])->values();
        $alerts = $priceHits->count();

        $ctas = [];
        if ($favCount === 0) {
            $ctas[] = 'Добавь игру в избранное и поставь целевую цену — вернёмся, когда станет дешевле.';
        } elseif ($favCount < 3) {
            $ctas[] = 'Ещё '.(3 - $favCount).' в избранном — и кабинет заработает на полную.';
        }
        if ($alerts) {
            $ctas[] = "{$alerts} игр(а) уже на цели или ниже — загляни в «На цели».";
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
