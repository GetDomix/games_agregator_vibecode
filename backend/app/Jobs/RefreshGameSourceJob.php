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
        try {
            $refresh->refresh($game, $this->source);
        } catch (\Throwable $e) {
            $refresh->recordFailure($game, $this->source, $e);
            throw $e;
        }
    }
}
