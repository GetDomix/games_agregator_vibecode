<?php

namespace Tests\Feature;

use App\Jobs\RefreshGameSourceJob;
use App\Models\CurrentGamePrice;
use App\Models\Favorite;
use App\Models\Game;
use App\Models\GameSourceState;
use App\Models\SteamRegionalPrice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
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

    public function test_force_refresh_is_queued_instead_of_calling_sources_inside_the_request(): void
    {
        Queue::fake();
        $game = Game::query()->create(['steam_appid' => 77, 'name' => 'Queued Refresh', 'release_status' => 'released']);
        CurrentGamePrice::query()->create([
            'game_id' => $game->id,
            'source' => 'steam',
            'offer_kind' => 'official',
            'min_price_rub' => 700,
            'observed_at' => now(),
        ]);

        $this->getJson('/api/prices?q=Queued%20Refresh&appid=77&force=1')
            ->assertOk()
            ->assertJsonPath('steam.price_rub', '700.00')
            ->assertJsonPath('refreshing', true);

        foreach (GameSourceState::SOURCES as $source) {
            Queue::assertPushed(RefreshGameSourceJob::class, fn (RefreshGameSourceJob $job) => $job->gameId === $game->id && $job->source === $source);
        }
    }

    public function test_background_poll_keeps_favorite_state_without_adding_history(): void
    {
        Sanctum::actingAs($user = User::factory()->create());
        $game = Game::query()->create(['steam_appid' => 88, 'name' => 'Polled Game', 'release_status' => 'released']);
        Favorite::query()->create(['user_id' => $user->id, 'appid' => 88, 'game_name' => 'Polled Game']);

        $this->getJson('/api/prices?q=Polled%20Game&appid=88&background=1')
            ->assertOk()
            ->assertJsonPath('is_favorite', true)
            ->assertJsonPath('saved_to_history', false);

        $this->assertDatabaseCount('search_histories', 0);
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

    public function test_search_candidate_exposes_honest_release_and_artwork_fallback_metadata(): void
    {
        Game::query()->create(['steam_appid' => 12, 'name' => 'Soon Without Art', 'release_status' => 'announced', 'header_image' => null]);

        $this->getJson('/api/search?q=Soon%20Without%20Art')->assertOk()
            ->assertJsonPath('candidates.0.release_status', 'announced')
            ->assertJsonPath('candidates.0.price_rub', null)
            ->assertJsonPath('candidates.0.tiny_image', 'https://shared.akamai.steamstatic.com/store_item_assets/steam/apps/12/capsule_231x87.jpg');
    }

    public function test_search_candidate_distinguishes_pending_steam_price_from_confirmed_ru_unavailability(): void
    {
        $pending = Game::query()->create(['steam_appid' => 1201, 'name' => 'Pending Steam Price', 'release_status' => 'released']);
        GameSourceState::query()->create([
            'game_id' => $pending->id,
            'source' => GameSourceState::SOURCE_STEAM,
            'status' => GameSourceState::STATUS_PENDING,
        ]);

        $confirmedMissing = Game::query()->create(['steam_appid' => 1202, 'name' => 'Confirmed Missing Price', 'release_status' => 'released']);
        GameSourceState::query()->create([
            'game_id' => $confirmedMissing->id,
            'source' => GameSourceState::SOURCE_STEAM,
            'status' => GameSourceState::STATUS_FRESH,
            'last_success_at' => now(),
        ]);

        $priced = Game::query()->create(['steam_appid' => 1203, 'name' => 'Stored Steam Price', 'release_status' => 'released']);
        CurrentGamePrice::query()->create([
            'game_id' => $priced->id,
            'source' => GameSourceState::SOURCE_STEAM,
            'offer_kind' => 'official',
            'min_price_rub' => 132,
            'observed_at' => now(),
        ]);

        $this->getJson('/api/search?q=Steam%20Price')->assertOk()
            ->assertJsonPath('candidates.0.available_in_ru', null)
            ->assertJsonPath('candidates.1.available_in_ru', true);

        $this->getJson('/api/search?q=Confirmed%20Missing')->assertOk()
            ->assertJsonPath('candidates.0.available_in_ru', false);

        $this->getJson('/api/prices?q=Pending%20Steam%20Price&appid=1201')->assertOk()
            ->assertJsonPath('steam.available_in_ru', null);
    }

    public function test_search_endpoint_returns_more_than_eight_matching_games_for_scrollable_picker(): void
    {
        for ($i = 1; $i <= 12; $i++) {
            Game::query()->create([
                'steam_appid' => 1300 + $i,
                'name' => sprintf('Scrollable Match %02d', $i),
                'release_status' => 'released',
            ]);
        }

        $this->getJson('/api/search?q=Scrollable%20Match')->assertOk()
            ->assertJsonCount(12, 'candidates');
    }

    public function test_browser_discovery_fills_a_partial_local_match_list_and_deduplicates_by_appid(): void
    {
        Game::query()->create([
            'steam_appid' => 1401,
            'name' => 'Outpost Local',
            'release_status' => 'released',
        ]);
        Http::fake([
            'https://store.steampowered.com/api/storesearch/*' => Http::response(['items' => [
                ['id' => 1401, 'name' => 'Outpost Local', 'type' => 'app', 'tiny_image' => 'https://cdn.test/1401.jpg', 'price' => ['final' => 13200, 'initial' => 13200]],
                ['id' => 1402, 'name' => 'Outpost Discovery', 'type' => 'app', 'tiny_image' => 'https://cdn.test/1402.jpg', 'price' => ['final' => 9900, 'initial' => 9900]],
            ]]),
        ]);

        $this->getJson('/api/search?q=Outpost&discover=1')->assertOk()
            ->assertJsonCount(2, 'candidates')
            ->assertJsonPath('candidates.0.appid', 1401)
            ->assertJsonPath('candidates.0.price_rub', 132)
            ->assertJsonPath('candidates.1.appid', 1402)
            ->assertJsonPath('meta.discovery_used', true);
    }

    public function test_browser_discovery_keeps_a_region_blocked_exact_title_ahead_of_ru_keyword_matches(): void
    {
        Http::fake(function (Request $request) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return ($query['cc'] ?? null) === 'US'
                ? Http::response(['items' => [
                    ['id' => 1091500, 'name' => 'Cyberpunk 2077', 'type' => 'app'],
                    ['id' => 2138330, 'name' => 'Cyberpunk 2077: Phantom Liberty', 'type' => 'app'],
                ]])
                : Http::response(['items' => [
                    ['id' => 4348910, 'name' => 'Probably Stolen - Cyberpunk Shopkeeper Sim', 'type' => 'app'],
                    ['id' => 1465260, 'name' => 'Cyberpunk SFX', 'type' => 'app'],
                ]]);
        });

        $this->getJson('/api/search?q=cyberpunk&discover=1')->assertOk()
            ->assertJsonPath('candidates.0.appid', 1091500)
            ->assertJsonPath('candidates.0.name', 'Cyberpunk 2077')
            ->assertJsonPath('candidates.0.available_in_ru', false)
            ->assertJsonPath('meta.discovery_used', true);
    }

    public function test_explicit_discovery_is_not_skipped_when_local_keyword_matches_fill_the_limit(): void
    {
        for ($i = 1; $i <= 20; $i++) {
            Game::query()->create([
                'steam_appid' => 8000 + $i,
                'name' => sprintf('Cyberpunk Local Match %02d', $i),
                'release_status' => 'released',
            ]);
        }
        Http::fake(function (Request $request) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return ($query['cc'] ?? null) === 'US'
                ? Http::response(['items' => [['id' => 1091500, 'name' => 'Cyberpunk 2077', 'type' => 'app']]])
                : Http::response(['items' => []]);
        });

        $this->getJson('/api/search?q=cyberpunk&discover=1')->assertOk()
            ->assertJsonCount(20, 'candidates')
            ->assertJsonPath('candidates.0.appid', 1091500)
            ->assertJsonPath('meta.discovery_used', true);
    }

    public function test_no_appid_never_selects_an_ambiguous_or_partial_stored_game(): void
    {
        Queue::fake();
        Game::query()->create(['steam_appid' => 501, 'name' => 'Control', 'release_status' => 'released']);
        Game::query()->create(['steam_appid' => 502, 'name' => 'Control', 'release_status' => 'released']);

        $this->getJson('/api/prices?q=Control&force=1')
            ->assertOk()
            ->assertJsonPath('steam', null)
            ->assertJsonCount(2, 'candidates')
            ->assertJsonPath('saved_to_history', false)
            ->assertJsonPath('refreshing', false);
        Queue::assertNothingPushed();
    }

    public function test_no_appid_partial_or_unique_exact_are_distinguished(): void
    {
        Queue::fake();
        Sanctum::actingAs(User::factory()->create());
        Game::query()->create(['steam_appid' => 503, 'name' => 'Control Ultimate Edition', 'release_status' => 'released']);
        $this->getJson('/api/prices?q=Control')->assertOk()->assertJsonPath('steam', null)->assertJsonPath('saved_to_history', false);
        $this->assertDatabaseCount('search_histories', 0);
        Queue::assertNothingPushed();
        $exact = Game::query()->create(['steam_appid' => 504, 'name' => 'Unique Exact', 'release_status' => 'released']);
        $this->getJson('/api/prices?q=Unique%20Exact')->assertOk()->assertJsonPath('steam.appid', $exact->steam_appid)->assertJsonPath('saved_to_history', true);
        $this->assertDatabaseCount('search_histories', 1);
        Queue::assertNothingPushed();
    }

    public function test_multiple_exact_names_remain_ambiguous_and_existing_appid_is_immediate(): void
    {
        Queue::fake();
        Sanctum::actingAs(User::factory()->create());
        Game::query()->create(['steam_appid' => 505, 'name' => 'Same', 'release_status' => 'released']);
        Game::query()->create(['steam_appid' => 506, 'name' => 'Same', 'release_status' => 'released']);

        $this->getJson('/api/prices?q=Same&force=1')->assertOk()
            ->assertJsonPath('steam', null)->assertJsonCount(2, 'candidates')
            ->assertJsonPath('saved_to_history', false)->assertJsonPath('refreshing', false);
        $this->assertDatabaseCount('search_histories', 0);
        Queue::assertNothingPushed();

        $selected = Game::query()->where('steam_appid', 505)->firstOrFail();
        $this->getJson('/api/prices?q=Same&appid=505')->assertOk()
            ->assertJsonPath('steam.appid', $selected->steam_appid)
            ->assertJsonPath('refreshing', false)
            ->assertJsonPath('saved_to_history', true);
        $this->assertDatabaseCount('search_histories', 1);
        Queue::assertNothingPushed();
    }

    public function test_exact_duplicates_outside_suggestion_limit_remain_ambiguous_without_queue_or_history(): void
    {
        Queue::fake();
        Sanctum::actingAs(User::factory()->create());
        for ($i = 0; $i < 30; $i++) {
            Game::query()->create(['steam_appid' => 6000 + $i, 'name' => "Overflow Partial {$i}", 'release_status' => 'released']);
        }
        Game::query()->create(['steam_appid' => 6101, 'name' => 'Overflow Exact', 'release_status' => 'released']);
        Game::query()->create(['steam_appid' => 6102, 'name' => 'Overflow Exact', 'release_status' => 'released']);

        $this->getJson('/api/prices?q=Overflow%20Exact&force=1')->assertOk()
            ->assertJsonPath('steam', null)
            ->assertJsonCount(2, 'candidates')
            ->assertJsonPath('candidates.0.name', 'Overflow Exact')
            ->assertJsonPath('saved_to_history', false);
        $this->assertDatabaseCount('search_histories', 0);
        Queue::assertNothingPushed();
    }
}
