<?php

namespace Tests\Feature;

use App\Jobs\RefreshGameSourceJob;
use App\Models\CurrentGamePrice;
use App\Models\Game;
use App\Models\GameSourceState;
use App\Models\SteamRegionalPrice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class StoredPriceSearchTest extends TestCase
{
    use RefreshDatabase;

    public function test_prices_reads_canonical_rows_without_source_requests(): void
    {
        $game = Game::query()->create(['steam_appid' => 10, 'name' => 'Stored Game', 'release_status' => 'released']);
        CurrentGamePrice::query()->create(['game_id' => $game->id, 'source' => 'steam', 'offer_kind' => 'official', 'min_price_rub' => 500, 'observed_at' => now()]);
        CurrentGamePrice::query()->create(['game_id' => $game->id, 'source' => 'plati', 'offer_kind' => 'key', 'min_price_rub' => 300, 'offer_count' => 2, 'observed_at' => now()]);
        GameSourceState::query()->create(['game_id' => $game->id, 'source' => 'steam', 'status' => 'fresh', 'last_success_at' => now()]);

        $this->getJson('/api/prices?q=Stored%20Game&appid=10')->assertOk()
            ->assertJsonPath('steam.name', 'Stored Game')->assertJsonPath('steam.price_rub', '500.00')
            ->assertJsonPath('plati.by_kind.0.kind', 'key')->assertJsonPath('refreshing', false);
    }

    public function test_unknown_appid_is_queued_without_waiting_for_source(): void
    {
        Queue::fake();
        $this->getJson('/api/prices?q=New%20Game&appid=999')->assertOk()
            ->assertJsonPath('steam.name', 'New Game')->assertJsonPath('refreshing', true);
        Queue::assertPushed(RefreshGameSourceJob::class, fn (RefreshGameSourceJob $job) => $job->gameId === Game::query()->where('steam_appid', 999)->value('id'));
    }

    public function test_prices_exposes_official_regional_steam_prices(): void
    {
        $game = Game::query()->create(['steam_appid' => 1091500, 'name' => 'Cyberpunk 2077', 'release_status' => 'released']);
        SteamRegionalPrice::query()->create([
            'game_id' => $game->id, 'region' => 'US', 'currency' => 'USD', 'price_amount' => 59.99, 'price_rub' => 4799.2, 'observed_at' => now(),
        ]);

        $this->getJson('/api/prices?q=Cyberpunk&appid=1091500')->assertOk()
            ->assertJsonPath('steam.price_rub', null)
            ->assertJsonPath('steam.regional_prices.0.region', 'US')
            ->assertJsonPath('steam.regional_prices.0.amount', 59.99)
            ->assertJsonPath('steam.regional_prices.0.price_rub', 4799.2);
    }

    public function test_announced_game_has_no_marketplace_offers(): void
    {
        $game = Game::query()->create(['steam_appid' => 11, 'name' => 'Soon', 'release_status' => 'announced']);
        CurrentGamePrice::query()->create(['game_id' => $game->id, 'source' => 'plati', 'offer_kind' => 'key', 'min_price_rub' => 10, 'observed_at' => now()]);
        $this->getJson('/api/prices?q=Soon&appid=11')->assertOk()->assertJsonCount(0, 'plati.by_kind')->assertJsonCount(0, 'ggsel.by_kind');
    }
}
