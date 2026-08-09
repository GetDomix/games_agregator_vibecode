<?php

namespace Tests\Feature;

use App\Models\CurrentGamePrice;
use App\Models\Game;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class PlatiRouletteTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_returns_a_real_saved_plati_offer(): void
    {
        Cache::put('steam-new-releases-v3', ['items' => []], 60);
        Cache::put('steam-weekly-deals-v1', ['items' => []], 60);
        $game = Game::query()->create(['steam_appid' => 1145360, 'name' => 'Hades', 'release_status' => 'released']);
        CurrentGamePrice::query()->create([
            'game_id' => $game->id,
            'source' => 'plati',
            'offer_kind' => 'key',
            'min_price_rub' => 499,
            'offer_count' => 1,
            'cheapest_offer_url' => 'https://plati.market/itm/123',
            'observed_at' => now(),
        ]);

        $this->getJson('/api/roulette/plati')
            ->assertOk()
            ->assertJsonPath('appid', 1145360)
            ->assertJsonPath('name', 'Hades')
            ->assertJsonPath('url', 'https://plati.market/itm/123');
    }

    public function test_it_can_pick_from_steam_showcases_when_the_local_catalog_is_empty(): void
    {
        Cache::put('steam-new-releases-v3', [
            'generated_at' => now()->toIso8601String(),
            'currency' => 'RUB',
            'source' => 'steam',
            'refresh_interval_minutes' => 30,
            'items' => [
                ['appid' => 10, 'name' => 'First External Game', 'header_image' => 'https://cdn.test/10.jpg'],
                ['appid' => 20, 'name' => 'Second External Game', 'header_image' => 'https://cdn.test/20.jpg'],
            ],
        ], 60);
        Cache::put('steam-weekly-deals-v1', ['items' => []], 60);

        $this->getJson('/api/roulette/plati?exclude=10')
            ->assertOk()
            ->assertJsonPath('appid', 20)
            ->assertJsonPath('name', 'Second External Game')
            ->assertJsonPath('source', 'steam_showcase')
            ->assertJsonPath('url', null);
    }
}
