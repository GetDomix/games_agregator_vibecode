<?php

namespace Tests\Feature;

use App\Contracts\PriceSourceAdapter;
use App\Data\PriceSourceResult;
use App\Jobs\RefreshGameSourceJob;
use App\Models\CurrentGamePrice;
use App\Models\Game;
use App\Models\GameSourceState;
use App\Models\SteamRegionalPrice;
use App\Models\User;
use App\Services\AlertEvaluationService;
use App\Services\DueGameRefreshDispatcher;
use App\Services\GamePriceRefreshService;
use App\Services\PriceSourceRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

class CentralPriceRefreshTest extends TestCase
{
    use RefreshDatabase;

    public function test_successful_refresh_updates_current_price_history_and_announced_schedule(): void
    {
        $game = Game::query()->create(['steam_appid' => 1, 'name' => 'Future Game']);
        $this->refreshService(new PriceSourceResult('steam', [[
            'kind' => 'official', 'min_price_rub' => 999, 'avg_price_rub' => null, 'offer_count' => 1,
            'cheapest' => ['title' => 'Future Game', 'url' => 'https://steam.test/1', 'price_rub' => 999, 'sales' => null],
            'popular' => ['title' => 'Future Game', 'url' => 'https://steam.test/1', 'price_rub' => 999, 'sales' => null],
        ]], 'Future Game', null, 'announced'))->refresh($game, 'steam');

        $this->assertDatabaseHas('current_game_prices', ['game_id' => $game->id, 'source' => 'steam', 'min_price_rub' => 999]);
        $this->assertDatabaseCount('game_price_observations', 1);
        $state = GameSourceState::query()->where('game_id', $game->id)->firstOrFail();
        $this->assertSame('fresh', $state->status);
        $this->assertSame(0, $state->consecutive_failures);
        $this->assertTrue($state->next_refresh_at->between(now()->addHours(23), now()->addHours(25)));
    }

    public function test_failure_preserves_last_price_and_applies_backoff(): void
    {
        $game = Game::query()->create(['steam_appid' => 2, 'name' => 'Broken Source']);
        CurrentGamePrice::query()->create(['game_id' => $game->id, 'source' => 'steam', 'offer_kind' => 'official', 'min_price_rub' => 500, 'observed_at' => now()]);
        $service = $this->refreshService(new \RuntimeException('source offline'));
        try {
            $service->refresh($game, 'steam');
        } catch (\Throwable $error) {
            $service->recordFailure($game, 'steam', $error);
        }

        $this->assertDatabaseHas('current_game_prices', ['game_id' => $game->id, 'min_price_rub' => 500]);
        $state = GameSourceState::query()->where('game_id', $game->id)->firstOrFail();
        $this->assertSame('failed', $state->status);
        $this->assertSame(1, $state->consecutive_failures);
        $this->assertTrue($state->next_refresh_at->isAfter(now()->addSeconds(30)));
        $this->assertTrue($state->next_refresh_at->isBefore(now()->addMinutes(2)));
    }

    public function test_steam_refresh_persists_regional_prices_without_mislabeling_them_as_rubles(): void
    {
        $game = Game::query()->create(['steam_appid' => 1091500, 'name' => 'Cyberpunk 2077']);
        $this->refreshService(new PriceSourceResult(
            source: 'steam',
            offerGroups: [],
            gameName: 'Cyberpunk 2077',
            regionalPrices: [['region' => 'US', 'currency' => 'USD', 'amount' => 59.99, 'price_rub' => 4799.2]],
        ))->refresh($game, 'steam');

        $regional = SteamRegionalPrice::query()->where('game_id', $game->id)->firstOrFail();
        $this->assertSame('US', $regional->region);
        $this->assertSame('USD', $regional->currency);
        $this->assertSame(59.99, $regional->price_amount);
        $this->assertSame(4799.2, $regional->price_rub);
        $this->assertDatabaseCount('current_game_prices', 0);
    }

    public function test_due_dispatch_skips_marketplaces_for_announced_games(): void
    {
        Queue::fake();
        $game = Game::query()->create(['steam_appid' => 3, 'name' => 'Announced', 'release_status' => 'announced']);
        GameSourceState::query()->create(['game_id' => $game->id, 'source' => 'steam', 'next_refresh_at' => now()->subMinute()]);
        $market = GameSourceState::query()->create(['game_id' => $game->id, 'source' => 'plati', 'next_refresh_at' => now()->subMinute()]);

        $this->assertSame(1, app(DueGameRefreshDispatcher::class)->dispatch());
        Queue::assertPushed(RefreshGameSourceJob::class, fn ($job) => $job->gameId === $game->id && $job->source === 'steam');
        Queue::assertNotPushed(RefreshGameSourceJob::class, fn ($job) => $job->source === 'plati');
        $this->assertTrue($market->fresh()->next_refresh_at->isAfter(now()->addHours(23)));
    }

    public function test_release_activates_existing_market_states(): void
    {
        $game = Game::query()->create(['steam_appid' => 4, 'name' => 'Launching', 'release_status' => 'announced']);
        $market = GameSourceState::query()->create(['game_id' => $game->id, 'source' => 'ggsel', 'next_refresh_at' => now()->addDay()]);
        $this->refreshService(new PriceSourceResult('steam', [], 'Launching', null, 'released'))->refresh($game, 'steam');

        $this->assertSame('released', $game->fresh()->release_status);
        $this->assertTrue($market->fresh()->next_refresh_at->isBefore(now()->addMinute()));
        $this->assertDatabaseHas('game_source_states', ['game_id' => $game->id, 'source' => 'plati', 'status' => 'pending']);
    }

    public function test_refresh_returns_the_number_of_created_alert_events(): void
    {
        $game = Game::query()->create(['steam_appid' => 41, 'name' => 'Alerted Game']);
        $adapter = Mockery::mock(PriceSourceAdapter::class);
        $adapter->shouldReceive('refresh')->once()->andReturn(new PriceSourceResult(
            'steam', [], 'Alerted Game', null, 'released'
        ));
        $registry = Mockery::mock(PriceSourceRegistry::class);
        $registry->shouldReceive('for')->once()->with('steam')->andReturn($adapter);
        $alerts = Mockery::mock(AlertEvaluationService::class);
        $alerts->shouldReceive('evaluate')->once()->andReturn(2);

        $this->assertSame(2, (new GamePriceRefreshService($registry, $alerts))->refresh($game, 'steam'));
    }

    public function test_favorite_request_is_queued_and_prices_api_is_read_only(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $response = $this->postJson('/api/me/favorites', ['appid' => 5, 'game_name' => 'Queued Game']);
        $response->assertCreated();
        $game = Game::query()->where('steam_appid', 5)->firstOrFail();
        $this->assertDatabaseHas('favorites', ['user_id' => $user->id, 'game_id' => $game->id]);
        Queue::assertPushed(RefreshGameSourceJob::class, fn ($job) => $job->gameId === $game->id && $job->source === 'steam');

        CurrentGamePrice::query()->create(['game_id' => $game->id, 'source' => 'steam', 'offer_kind' => 'official', 'min_price_rub' => 100, 'observed_at' => now()]);
        GameSourceState::query()->updateOrCreate(['game_id' => $game->id, 'source' => 'steam'], ['status' => 'failed', 'next_refresh_at' => now()]);
        $this->getJson('/api/games/5/prices')->assertOk()->assertJsonPath('game.name', 'Queued Game')->assertJsonPath('sources.0.has_error', true);
    }

    private function refreshService(PriceSourceResult|\Throwable $outcome): GamePriceRefreshService
    {
        $adapter = Mockery::mock(PriceSourceAdapter::class);
        $adapter->shouldReceive('refresh')->andReturnUsing(function () use ($outcome) {
            if ($outcome instanceof \Throwable) {
                throw $outcome;
            }

            return $outcome;
        });
        $registry = Mockery::mock(PriceSourceRegistry::class);
        $registry->shouldReceive('for')->andReturn($adapter);

        return new GamePriceRefreshService($registry);
    }
}
