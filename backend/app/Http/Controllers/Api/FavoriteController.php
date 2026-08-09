<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Favorite;
use App\Services\FavoriteAlertSettingsService;
use App\Services\GameRefreshRequestService;
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
            ->with(['alert.scopes', 'game.sourceStates'])->get()
            ->map->toApiArray()
            ->values();
        $hits = $items->filter(fn ($i) => $i['price_below_target'])->values();

        return response()->json([
            'items' => $items,
            'total' => $items->count(),
            'price_hits' => $hits,
        ]);
    }

    public function store(Request $request, GameRefreshRequestService $refresh, FavoriteAlertSettingsService $alerts): JsonResponse
    {
        $data = $request->validate([
            'appid' => ['required', 'integer', 'min:1'],
            'game_name' => ['required', 'string', 'max:200'],
            'header_image' => ['nullable', 'string', 'max:500', 'regex:/^https?:\/\//i'],
            'notes' => ['nullable', 'string', 'max:500'],
            'target_price_rub' => ['nullable', 'numeric', 'min:0'],
            'last_steam_price_rub' => ['prohibited'],
            'alert' => ['nullable', 'array'], 'alert.target_value' => ['nullable', 'numeric', 'min:0'], 'alert.condition_type' => ['nullable', 'in:target_price'], 'alert.scopes' => ['nullable', 'array', 'min:1'], 'alert.scopes.*.source' => ['required_with:alert.scopes', 'string'], 'alert.scopes.*.offer_kind' => ['required_with:alert.scopes', 'string'],
        ]);
        $name = trim($data['game_name']);
        if ($name === '') {
            return response()->json(['detail' => 'Название не может быть пустым'], 422);
        }

        $fav = Favorite::query()->firstOrNew([
            'user_id' => $request->user()->id,
            'appid' => $data['appid'],
        ]);
        $isNew = ! $fav->exists;
        if (! $fav->exists && Favorite::query()->where('user_id', $request->user()->id)->count() >= 200) {
            return response()->json(['detail' => 'Лимит избранного: 200 игр'], 400);
        }
        $fav->fill([
            'game_name' => mb_substr($name, 0, 200),
            'header_image' => $data['header_image'] ?? $fav->header_image,
            'notes' => $data['notes'] ?? $fav->notes,
            'target_price_rub' => array_key_exists('target_price_rub', $data) ? $data['target_price_rub'] : $fav->target_price_rub,
        ]);
        $fav->save();
        $refresh->linkFavorite($fav);
        if ($isNew || ! $fav->alert()->exists() || array_key_exists('alert', $data) || array_key_exists('target_price_rub', $data)) {
            try {
                $alerts->save($fav, $data['alert'] ?? ['target_value' => $fav->target_price_rub]);
            } catch (\InvalidArgumentException $e) {
                return response()->json(['detail' => $e->getMessage()], 422);
            }
        }
        $fav->load(['alert.scopes', 'game.sourceStates']);

        return response()->json($fav->toApiArray(), $fav->wasRecentlyCreated ? 201 : 200);
    }

    public function update(Request $request, int $appid, FavoriteAlertSettingsService $alerts): JsonResponse
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
            'last_steam_price_rub' => ['prohibited'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:500'],
            'alert' => ['sometimes', 'array'], 'alert.target_value' => ['nullable', 'numeric', 'min:0'], 'alert.condition_type' => ['nullable', 'in:target_price'], 'alert.scopes' => ['nullable', 'array', 'min:1'], 'alert.scopes.*.source' => ['required_with:alert.scopes', 'string'], 'alert.scopes.*.offer_kind' => ['required_with:alert.scopes', 'string'],
        ]);
        $fav->fill(collect($data)->except('alert')->all())->save();
        try {
            if (isset($data['alert'])) {
                $alerts->save($fav, $data['alert']);
            }
        } catch (\InvalidArgumentException $e) {
            return response()->json(['detail' => $e->getMessage()], 422);
        } $fav->load(['alert.scopes', 'game.sourceStates']);

        return response()->json($fav->toApiArray());
    }

    public function rearm(Request $request, int $appid, FavoriteAlertSettingsService $alerts): JsonResponse
    {
        $fav = Favorite::query()->where('user_id', $request->user()->id)->where('appid', $appid)->firstOrFail();
        $alerts->rearm($fav);

        return response()->json($fav->fresh()->load(['alert.scopes', 'game.sourceStates'])->toApiArray());
    }

    public function destroyAlert(Request $request, int $appid, FavoriteAlertSettingsService $alerts): Response
    {
        $fav = Favorite::query()->where('user_id', $request->user()->id)->where('appid', $appid)->firstOrFail();
        $alerts->remove($fav);

        return response()->noContent();
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

    public function refresh(Request $request, GameRefreshRequestService $refresh): JsonResponse
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
            $game = $refresh->linkFavorite($fav);
            $item = $fav->fresh()->toApiArray();
            $refreshed[] = [
                'appid' => $fav->appid,
                'game_name' => $fav->game_name,
                'ok' => true,
                'queued' => true,
                'last_steam_price_rub' => $item['last_steam_price_rub'],
                'target_price_rub' => $item['target_price_rub'],
                'price_below_target' => $item['price_below_target'],
                'market_min_rub' => null,
                'game_id' => $game->id,
            ];
        }
        $hits = count(array_filter($refreshed, fn ($r) => ! empty($r['price_below_target'])));
        $ok = count(array_filter($refreshed, fn ($r) => ! empty($r['ok'])));
        $msg = "Поставлено в очередь {$ok} из ".count($refreshed).'.';
        if ($hits) {
            $msg .= " {$hits} на цели или ниже.";
        }

        return response()->json(['refreshed' => $refreshed, 'skipped' => 0, 'message' => $msg]);
    }
}
