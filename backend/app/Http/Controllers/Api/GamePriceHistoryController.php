<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Game;
use App\Services\Pricing\GamePriceHistoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class GamePriceHistoryController extends Controller
{
    public function show(Request $request, int $appid, GamePriceHistoryService $history): JsonResponse
    {
        $game = $this->findGame($appid);

        return response()->json($history->overview($game, $this->period($request)));
    }

    public function authenticated(Request $request, int $appid, GamePriceHistoryService $history): JsonResponse
    {
        $game = $this->findGame($appid);
        $days = $this->period($request);

        return response()->json([
            ...$history->overview($game, $days),
            'changes' => $history->changes($game, $days),
        ]);
    }

    private function findGame(int $appid): Game
    {
        $game = Game::query()->where('steam_appid', $appid)->first();
        abort_if(! $game, 404, 'Игра пока не добавлена в ценовое хранилище');

        return $game;
    }

    private function period(Request $request): int
    {
        $validated = $request->validate([
            'days' => ['sometimes', 'integer', Rule::in([30, 90, 365])],
        ]);

        return (int) ($validated['days'] ?? 90);
    }
}
