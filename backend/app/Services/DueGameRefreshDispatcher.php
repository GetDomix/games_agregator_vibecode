<?php

namespace App\Services;

use App\Jobs\RefreshGameSourceJob;
use App\Models\Game;
use App\Models\GameSourceState;

class DueGameRefreshDispatcher
{
    public function dispatch(): int
    {
        $limit = max(1, (int) config('gpa.price_refresh_dispatch_batch', 100));
        $states = GameSourceState::query()
            ->with('game')
            ->whereNotNull('next_refresh_at')
            ->where('next_refresh_at', '<=', now())
            ->orderBy('next_refresh_at')
            ->limit($limit)
            ->get();
        $count = 0;
        foreach ($states as $state) {
            $game = $state->game;
            if (! $game instanceof Game) {
                continue;
            }
            if ($state->source !== GameSourceState::SOURCE_STEAM && ! $game->isReleased()) {
                $state->forceFill(['next_refresh_at' => now()->addHours(24), 'status' => GameSourceState::STATUS_PENDING])->save();

                continue;
            }
            RefreshGameSourceJob::dispatch($game->id, $state->source)->onQueue(config('gpa.price_refresh_queue'));
            $count++;
        }

        return $count;
    }
}
