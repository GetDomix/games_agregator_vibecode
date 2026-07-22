<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Favorite;
use App\Services\AggregatorService;
use App\Services\DealScoreService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class FavoriteController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $items = Favorite::query()
            ->where('user_id', $request->user()->id)
            ->orderByDesc('updated_at')
            ->get()
            ->map->toApiArray()
            ->values();
        $hits = $items->filter(fn ($i) => $i['price_below_target'])->values();

        return response()->json([
            'items' => $items,
            'total' => $items->count(),
            'price_hits' => $hits,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'appid' => ['required', 'integer', 'min:1'],
            'game_name' => ['required', 'string', 'max:200'],
            'header_image' => ['nullable', 'string', 'max:500', 'regex:/^https?:\/\//i'],
            'notes' => ['nullable', 'string', 'max:500'],
            'target_price_rub' => ['nullable', 'numeric', 'min:0'],
            'last_steam_price_rub' => ['nullable', 'numeric', 'min:0'],
        ]);
        $name = trim($data['game_name']);
        if ($name === '') {
            return response()->json(['detail' => 'Название не может быть пустым'], 422);
        }

        $fav = Favorite::query()->firstOrNew([
            'user_id' => $request->user()->id,
            'appid' => $data['appid'],
        ]);
        if (! $fav->exists && Favorite::query()->where('user_id', $request->user()->id)->count() >= 200) {
            return response()->json(['detail' => 'Лимит избранного: 200 игр'], 400);
        }
        $fav->fill([
            'game_name' => mb_substr($name, 0, 200),
            'header_image' => $data['header_image'] ?? $fav->header_image,
            'notes' => $data['notes'] ?? $fav->notes,
            'target_price_rub' => array_key_exists('target_price_rub', $data) ? $data['target_price_rub'] : $fav->target_price_rub,
            'last_steam_price_rub' => array_key_exists('last_steam_price_rub', $data) ? $data['last_steam_price_rub'] : $fav->last_steam_price_rub,
        ]);
        $fav->save();

        return response()->json($fav->toApiArray(), $fav->wasRecentlyCreated ? 201 : 200);
    }

    public function update(Request $request, int $appid): JsonResponse
    {
        $fav = Favorite::query()
            ->where('user_id', $request->user()->id)
            ->where('appid', $appid)
            ->first();
        if (! $fav) {
            return response()->json(['detail' => 'Игра не в избранном'], 404);
        }
        $data = $request->validate([
            'target_price_rub' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:500'],
            'last_steam_price_rub' => ['sometimes', 'nullable', 'numeric', 'min:0'],
        ]);
        $fav->fill($data)->save();

        return response()->json($fav->toApiArray());
    }

    public function destroy(Request $request, int $appid): Response
    {
        $fav = Favorite::query()
            ->where('user_id', $request->user()->id)
            ->where('appid', $appid)
            ->first();
        if (! $fav) {
            return response()->json(['detail' => 'Игра не в избранном'], 404);
        }
        $fav->delete();

        return response()->noContent();
    }

    public function refresh(Request $request, AggregatorService $aggregator): JsonResponse
    {
        $limit = min((int) $request->query('limit', 5), (int) config('gpa.watchlist_refresh_max', 5));
        $rows = Favorite::query()
            ->where('user_id', $request->user()->id)
            ->orderByDesc('updated_at')
            ->limit($limit)
            ->get();

        if ($rows->isEmpty()) {
            return response()->json([
                'refreshed' => [],
                'skipped' => 0,
                'message' => 'В избранном пока пусто — добавь игру с карточки Steam.',
            ]);
        }

        $refreshed = [];
        foreach ($rows as $fav) {
            try {
                $result = $aggregator->aggregate($fav->game_name, (int) $fav->appid);
                $steamPrice = $result['steam']['price_rub'] ?? null;
                if ($steamPrice !== null) {
                    $fav->last_steam_price_rub = $steamPrice;
                }
                if (! empty($result['steam']['header_image'])) {
                    $fav->header_image = $result['steam']['header_image'];
                }
                if (! empty($result['steam']['name'])) {
                    $fav->game_name = mb_substr($result['steam']['name'], 0, 200);
                }
                $fav->save();
                $deal = DealScoreService::compute(
                    $steamPrice ?? $fav->last_steam_price_rub,
                    $result['plati'],
                    $result['ggsel']
                );
                $item = $fav->toApiArray();
                $refreshed[] = [
                    'appid' => $fav->appid,
                    'game_name' => $fav->game_name,
                    'ok' => true,
                    'last_steam_price_rub' => $item['last_steam_price_rub'],
                    'target_price_rub' => $item['target_price_rub'],
                    'price_below_target' => $item['price_below_target'],
                    'market_min_rub' => $deal['market_min_rub'],
                ];
            } catch (\Throwable $e) {
                $refreshed[] = [
                    'appid' => $fav->appid,
                    'game_name' => $fav->game_name,
                    'ok' => false,
                    'last_steam_price_rub' => $fav->last_steam_price_rub,
                    'target_price_rub' => $fav->target_price_rub,
                    'price_below_target' => false,
                    'error' => mb_substr($e->getMessage(), 0, 200),
                ];
            }
        }
        $hits = count(array_filter($refreshed, fn ($r) => ! empty($r['price_below_target'])));
        $ok = count(array_filter($refreshed, fn ($r) => ! empty($r['ok'])));
        $msg = "Обновлено {$ok} из ".count($refreshed).'.';
        if ($hits) {
            $msg .= " {$hits} на цели или ниже.";
        }

        return response()->json(['refreshed' => $refreshed, 'skipped' => 0, 'message' => $msg]);
    }
}
