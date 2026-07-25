<?php

namespace App\Services;

use App\Jobs\RefreshGameSourceJob;
use App\Models\Favorite;
use App\Models\Game;
use App\Models\GameSourceState;

class GameRefreshRequestService
{
    public function requestUnknown(int $appid, string $name): Game
    {
        $game = Game::query()->firstOrCreate(['steam_appid' => $appid], ['name' => mb_substr(trim($name) ?: "Steam app {$appid}", 0, 200)]);
        $this->request($game, [GameSourceState::SOURCE_STEAM]);

        return $game;
    }

    public function linkFavorite(Favorite $favorite): Game
    {
        $game = Game::query()->firstOrCreate(
            ['steam_appid' => (int) $favorite->appid],
            ['name' => $favorite->game_name, 'header_image' => $favorite->header_image]
        );
        if ($favorite->game_id !== $game->id) {
            $favorite->game()->associate($game);
            $favorite->save();
        }
        $this->request($game, [GameSourceState::SOURCE_STEAM]);

        return $game;
    }

    /** @param array<int, string> $sources */
    public function request(Game $game, array $sources = [GameSourceState::SOURCE_STEAM]): void
    {
        foreach (array_unique($sources) as $source) {
            if (! in_array($source, GameSourceState::SOURCES, true)) {
                continue;
            }
            $state = GameSourceState::query()->firstOrCreate(
                ['game_id' => $game->id, 'source' => $source],
                ['status' => GameSourceState::STATUS_PENDING, 'next_refresh_at' => now()]
            );
            $state->forceFill(['status' => GameSourceState::STATUS_PENDING, 'next_refresh_at' => now()])->save();
            RefreshGameSourceJob::dispatch($game->id, $source)->onQueue(config('gpa.price_refresh_queue'));
        }
    }
}
