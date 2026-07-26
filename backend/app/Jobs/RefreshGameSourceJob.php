<?php

namespace App\Jobs;

use App\Models\Game;
use App\Services\GamePriceRefreshService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class RefreshGameSourceJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $uniqueFor = 1200;

    public int $tries = 4;

    public int $timeout = 90;

    public function __construct(public readonly int $gameId, public readonly string $source) {}

    public function uniqueId(): string
    {
        return "{$this->gameId}:{$this->source}";
    }

    public function middleware(): array
    {
        return [(new WithoutOverlapping('price-refresh:'.$this->uniqueId()))->releaseAfter(30), new RateLimited('price-source-'.$this->source)];
    }

    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function handle(GamePriceRefreshService $refresh): void
    {
        $game = Game::query()->findOrFail($this->gameId);
        $startedAt = hrtime(true);
        try {
            $eventsCreated = $refresh->refresh($game, $this->source);
            $state = $game->sourceStates()->where('source', $this->source)->first();
            Log::info('price_refresh_completed', [
                'game_id' => $game->id,
                'source' => $this->source,
                'duration_ms' => round((hrtime(true) - $startedAt) / 1_000_000, 2),
                'status' => $state?->status,
                'consecutive_failures' => $state?->consecutive_failures,
                'events_created' => $eventsCreated,
            ]);
        } catch (\Throwable $e) {
            $refresh->recordFailure($game, $this->source, $e);
            $state = $game->sourceStates()->where('source', $this->source)->first();
            Log::error('price_refresh_failed', [
                'game_id' => $game->id,
                'source' => $this->source,
                'duration_ms' => round((hrtime(true) - $startedAt) / 1_000_000, 2),
                'status' => $state?->status,
                'consecutive_failures' => $state?->consecutive_failures,
                'error_class' => $e::class,
            ]);
            throw $e;
        }
    }
}
