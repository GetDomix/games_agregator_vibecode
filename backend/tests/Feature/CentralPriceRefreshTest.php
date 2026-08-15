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
use App\Services\Alerts\AlertEvaluationService;
use App\Services\Pricing\DueGameRefreshDispatcher;
use App\Services\Pricing\GamePriceRefreshService;
use App\Services\Pricing\PriceSourceRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
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
        $this->assertSame('source_refresh_failed', $state->last_error);
        $this->assertStringNotContainsString('source offline', (string) $state->last_error);
        $this->assertSame(1, $state->consecutive_failures);
        $this->assertTrue($state->next_refresh_at->isAfter(now()->addSeconds(30)));
        $this->assertTrue($state->next_refresh_at->isBefore(now()->addMinutes(2)));
    }

    public function test_partial_steam_region_transport_failure_preserves_the_last_complete_snapshot(): void
    {
        config(['gpa.steam_price_regions' => [
            ['region' => 'RU', 'country' => 'ru', 'language' => 'russian', 'currency' => 'RUB', 'label' => 'Россия'],
            ['region' => 'US', 'country' => 'us', 'language' => 'english', 'currency' => 'USD', 'label' => 'США'],
        ]]);
        Http::fake(function (Request $request) {
            if (str_contains($request->url(), 'www.cbr.ru')) {
                return Http::response('<ValCurs><Valute><CharCode>USD</CharCode><Nominal>1</Nominal><Value>80,0000</Value></Valute></ValCurs>');
            }

            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            if (($query['cc'] ?? null) === 'ru') {
                return Http::response('', 500);
            }

            return Http::response([
                '1091500' => ['success' => true, 'data' => [
                    'name' => 'Cyberpunk 2077',
                    'is_free' => false,
                    'price_overview' => ['currency' => 'USD', 'final' => 5999],
                    'release_date' => ['coming_soon' => false],
                ]],
            ]);
        });

        $game = Game::query()->create(['steam_appid' => 1091500, 'name' => 'Cyberpunk 2077', 'release_status' => 'released']);
        foreach ([
            ['region' => 'RU', 'currency' => 'RUB', 'price_amount' => 2999, 'price_rub' => 2999],
            ['region' => 'US', 'currency' => 'USD', 'price_amount' => 49.99, 'price_rub' => 3999.2],
        ] as $regional) {
            SteamRegionalPrice::query()->create([
                'game_id' => $game->id,
                ...$regional,
                'observed_at' => now()->subHour(),
            ]);
        }

        $service = app(GamePriceRefreshService::class);
        $error = null;
        try {
            $service->refresh($game, GameSourceState::SOURCE_STEAM);
        } catch (\Throwable $caught) {
            $error = $caught;
        }
        $this->assertInstanceOf(\RuntimeException::class, $error);
        $service->recordFailure($game, GameSourceState::SOURCE_STEAM, $error);

        $this->assertDatabaseHas('steam_regional_prices', [
            'game_id' => $game->id,
            'region' => 'RU',
            'price_amount' => 2999,
            'price_rub' => 2999,
        ]);
        $this->assertDatabaseHas('steam_regional_prices', [
            'game_id' => $game->id,
            'region' => 'US',
            'price_amount' => 49.99,
            'price_rub' => 3999.2,
        ]);
        $state = GameSourceState::query()->where('game_id', $game->id)->where('source', 'steam')->firstOrFail();
        $this->assertSame(GameSourceState::STATUS_FAILED, $state->status);
        $this->assertSame(GameSourceState::ERROR_REFRESH_FAILED, $state->last_error);
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
        $this->assertSame(GameSourceState::STATUS_STALE, $market->fresh()->status);

        $game->sourceStates()->where('source', 'steam')->update(['status' => GameSourceState::STATUS_FRESH]);
        $this->getJson('/api/prices?q=Announced&appid=3')
            ->assertOk()
            ->assertJsonPath('refreshing', false);
    }

    public function test_marketplace_waiting_for_steam_does_not_remain_pending(): void
    {
        $game = Game::query()->create(['steam_appid' => 33, 'name' => 'Unconfirmed Game']);

        $this->refreshService(new PriceSourceResult('plati', []))->refresh($game, 'plati');

        $state = GameSourceState::query()->where('game_id', $game->id)->where('source', 'plati')->firstOrFail();
        $this->assertSame(GameSourceState::STATUS_STALE, $state->status);
        $this->assertTrue($state->next_refresh_at->isAfter(now()->addHours(23)));
        $this->getJson('/api/prices?q=Unconfirmed%20Game&appid=33')
            ->assertOk()
            ->assertJsonPath('refreshing', false);
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
        $this->getJson('/api/games/5/prices')
            ->assertOk()
            ->assertJsonPath('game.name', 'Queued Game')
            ->assertJsonFragment(['source' => 'steam', 'has_error' => true]);
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
