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
        $limit = min(max((int) $request->query('limit', 10), 1), 30);
        $weekAgo = now()->subDays(7);
        $rows = SearchHistory::query()
            ->selectRaw('query, count(*) as cnt, max(appid) as appid, max(game_name) as game_name, max(header_image) as header_image')
            ->where('created_at', '>=', $weekAgo)
            ->groupBy('query')
            ->orderByDesc('cnt')
            ->limit($limit)
            ->get();

        if ($rows->isEmpty()) {
            $seed = [
                ['query' => 'Hades', 'game_name' => 'Hades', 'appid' => 1145360, 'count' => 0],
                ['query' => 'Cyberpunk 2077', 'game_name' => 'Cyberpunk 2077', 'appid' => 1091500, 'count' => 0],
                ['query' => 'Elden Ring', 'game_name' => 'ELDEN RING', 'appid' => 1245620, 'count' => 0],
                ['query' => 'Stardew Valley', 'game_name' => 'Stardew Valley', 'appid' => 413150, 'count' => 0],
                ['query' => 'Balatro', 'game_name' => 'Balatro', 'appid' => 2379780, 'count' => 0],
            ];

            return response()->json(['items' => array_slice($seed, 0, $limit), 'source' => 'seed']);
        }

        $items = $rows->map(fn ($r) => [
            'query' => $r->query,
            'count' => (int) $r->cnt,
            'appid' => $r->appid ? (int) $r->appid : null,
            'game_name' => $r->game_name,
            'header_image' => $r->header_image,
        ])->values();

        return response()->json(['items' => $items, 'source' => 'community']);
    }
}
