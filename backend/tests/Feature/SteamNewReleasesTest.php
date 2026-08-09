<?php

namespace Tests\Feature;

use App\Models\CurrentGamePrice;
use App\Models\Game;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SteamNewReleasesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::forget('steam-new-releases-v3');
        Cache::forget('steam-new-releases-v2');
        Cache::forget('steam-new-releases-last-success');
    }

    public function test_it_normalizes_new_release_items_from_steam(): void
    {
        Http::fake(['store.steampowered.com/*' => Http::response([
            'new_releases' => ['items' => [[
                'id' => 123,
                'type' => 0,
                'name' => 'Fresh Game',
                'header_image' => 'https://cdn.test/fresh.jpg',
                'final_price' => 129900,
                'discount_percent' => 10,
            ]]],
        ])]);

        $this->getJson('/api/releases/steam')
            ->assertOk()
            ->assertJsonPath('currency', 'RUB')
            ->assertJsonPath('refresh_interval_minutes', 30)
            ->assertJsonPath('items.0.appid', 123)
            ->assertJsonPath('items.0.price_final_rub', 1299);
    }

    public function test_it_uses_last_successful_steam_showcase_during_a_temporary_outage(): void
    {
        Http::fake(['store.steampowered.com/*' => Http::sequence()->push([
            'new_releases' => ['items' => [[
                'id' => 321,
                'type' => 0,
                'name' => 'Remembered Release',
                'header_image' => 'https://cdn.test/remembered.jpg',
                'final_price' => 49900,
            ]]],
        ])->push('', 503)->push('', 503)->push('', 503)]);
        $this->getJson('/api/releases/steam')->assertOk()->assertJsonPath('items.0.appid', 321);

        Cache::forget('steam-new-releases-v3');
        $this->getJson('/api/releases/steam')
            ->assertOk()
            ->assertJsonPath('source', 'steam_stale')
            ->assertJsonPath('items.0.appid', 321);
    }

    public function test_it_uses_the_official_steam_search_showcase_when_featured_categories_are_empty(): void
    {
        Http::fake([
            'store.steampowered.com/api/featuredcategories*' => Http::response(['new_releases' => ['items' => []]]),
            'store.steampowered.com/search/*' => Http::response('<a class="search_result_row" data-ds-appid="789"><img src="https://cdn.test/789.jpg"><span class="title">Search Release</span></a>'),
        ]);

        $this->getJson('/api/releases/steam')
            ->assertOk()
            ->assertJsonPath('source', 'steam_search')
            ->assertJsonPath('items.0.appid', 789)
            ->assertJsonPath('items.0.name', 'Search Release');
    }

    public function test_it_falls_back_to_recent_locally_known_releases_when_steam_is_unavailable(): void
    {
        Cache::forget('steam-new-releases-v2');
        Http::fake(['store.steampowered.com/*' => Http::response('', 503)]);
        $game = Game::query()->create([
            'steam_appid' => 456,
            'name' => 'Local Fresh Game',
            'header_image' => 'https://cdn.test/local.jpg',
            'release_status' => Game::RELEASE_STATUS_RELEASED,
            'release_date' => now()->subDay(),
        ]);
        CurrentGamePrice::query()->create([
            'game_id' => $game->id,
            'source' => 'steam',
            'offer_kind' => 'official',
            'min_price_rub' => 899,
            'observed_at' => now(),
        ]);

        $this->getJson('/api/releases/steam')
            ->assertOk()
            ->assertJsonPath('source', 'local_fallback')
            ->assertJsonPath('items.0.appid', 456)
            ->assertJsonPath('items.0.price_final_rub', 899);
    }

    public function test_it_does_not_cache_an_empty_showcase_after_a_temporary_outage(): void
    {
        $sequence = Http::sequence();
        $attemptsPerEndpoint = max(1, (int) config('gpa.http_max_retries', 2));
        foreach (range(1, $attemptsPerEndpoint * 2) as $_) {
            $sequence->push('', 503);
        }
        $sequence->push([
            'new_releases' => ['items' => [[
                'id' => 987,
                'type' => 0,
                'name' => 'Recovered Release',
            ]]],
        ]);
        Http::fake(['store.steampowered.com/*' => $sequence]);

        $this->getJson('/api/releases/steam')->assertOk()->assertJsonCount(0, 'items');

        $this->getJson('/api/releases/steam')
            ->assertOk()
            ->assertJsonPath('items.0.appid', 987);
    }
}
