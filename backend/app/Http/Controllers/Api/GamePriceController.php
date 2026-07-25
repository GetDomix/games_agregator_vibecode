<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Game;
use Illuminate\Http\JsonResponse;

class GamePriceController extends Controller
{
    public function show(int $appid): JsonResponse
    {
        $game = Game::query()->where('steam_appid', $appid)
            ->with(['currentPrices', 'sourceStates'])
            ->first();
        if (! $game) {
            return response()->json(['detail' => 'Игра пока не добавлена в ценовое хранилище'], 404);
        }

        return response()->json([
            'game' => [
                'appid' => (int) $game->steam_appid,
                'name' => $game->name,
                'header_image' => $game->header_image,
                'release_status' => $game->release_status,
                'release_date' => $game->release_date?->toDateString(),
            ],
            'prices' => $game->currentPrices->map(fn ($price) => [
                'source' => $price->source,
                'offer_kind' => $price->offer_kind,
                'min_price_rub' => $price->min_price_rub,
                'avg_price_rub' => $price->avg_price_rub,
                'offer_count' => $price->offer_count,
                'cheapest_offer_title' => $price->cheapest_offer_title,
                'cheapest_offer_url' => $price->cheapest_offer_url,
                'popular_offer_title' => $price->popular_offer_title,
                'popular_offer_url' => $price->popular_offer_url,
                'popular_offer_price_rub' => $price->popular_offer_price_rub,
                'popular_offer_sales' => $price->popular_offer_sales,
                'observed_at' => $price->observed_at?->toIso8601String(),
            ])->values(),
            'sources' => $game->sourceStates->map(fn ($state) => [
                'source' => $state->source,
                'status' => $state->status,
                'last_success_at' => $state->last_success_at?->toIso8601String(),
                'next_refresh_at' => $state->next_refresh_at?->toIso8601String(),
                'has_error' => $state->status === 'failed',
            ])->values(),
        ]);
    }
}
