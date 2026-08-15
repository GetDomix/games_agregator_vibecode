<?php

namespace Tests\Feature;

use App\Models\AlertEvent;
use App\Models\CurrentGamePrice;
use App\Models\Favorite;
use App\Models\FavoriteAlert;
use App\Models\Game;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DashboardAlertSummaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_counts_real_targets_and_uses_the_triggered_cross_source_event(): void
    {
        Sanctum::actingAs($user = User::factory()->create());
        $game = Game::query()->create([
            'steam_appid' => 99001,
            'name' => 'Cross-source target',
            'release_status' => Game::RELEASE_STATUS_RELEASED,
        ]);
        $favorite = Favorite::query()->create([
            'user_id' => $user->id,
            'game_id' => $game->id,
            'appid' => $game->steam_appid,
            'game_name' => $game->name,
            // Legacy Steam fields deliberately look like a hit. They must no
            // longer drive the unified dashboard.
            'target_price_rub' => 1000,
            'last_steam_price_rub' => 100,
        ]);
        $alert = FavoriteAlert::query()->create([
            'favorite_id' => $favorite->id,
            'target_value' => 500,
            'status' => 'active',
        ]);

        $this->getJson('/api/me/dashboard')
            ->assertOk()
            ->assertJsonPath('alerts_count', 1)
            ->assertJsonCount(0, 'price_hits');

        $alert->update(['status' => 'triggered', 'triggered_at' => now()]);
        AlertEvent::query()->create([
            'favorite_alert_id' => $alert->id,
            'alert_cycle' => 0,
            'user_id' => $user->id,
            'favorite_id' => $favorite->id,
            'game_id' => $game->id,
            'source' => 'plati',
            'offer_kind' => 'gift',
            'offer_price_rub' => 450,
            'offer_title' => 'Plati gift',
            'offer_url' => 'https://example.test/plati-gift',
            'observed_at' => now(),
        ]);

        $this->getJson('/api/me/dashboard')
            ->assertOk()
            ->assertJsonPath('alerts_count', 1)
            ->assertJsonPath('price_hits.0.appid', 99001)
            ->assertJsonPath('price_hits.0.target_price_rub', 500)
            ->assertJsonPath('price_hits.0.hit_price_rub', 450)
            ->assertJsonPath('price_hits.0.hit_source', 'plati')
            ->assertJsonPath('price_hits.0.hit_offer_kind', 'gift');
    }

    public function test_dashboard_preview_exposes_a_top_level_stored_suggestion_for_a_plain_favorite(): void
    {
        Http::fake();
        Sanctum::actingAs($user = User::factory()->create());
        $game = Game::query()->create([
            'steam_appid' => 99002,
            'name' => 'Dashboard hint',
            'release_status' => Game::RELEASE_STATUS_RELEASED,
        ]);
        $favorite = Favorite::query()->create([
            'user_id' => $user->id,
            'game_id' => $game->id,
            'appid' => $game->steam_appid,
            'game_name' => $game->name,
        ]);
        CurrentGamePrice::query()->create([
            'game_id' => $game->id,
            'source' => 'steam',
            'offer_kind' => 'official',
            'min_price_rub' => 1000,
            'observed_at' => now(),
        ]);

        $this->getJson('/api/me/dashboard')
            ->assertOk()
            ->assertJsonPath('favorites_preview.0.id', $favorite->id)
            ->assertJsonPath('favorites_preview.0.alert', null)
            ->assertJsonPath('favorites_preview.0.suggested_target.value_rub', 900)
            ->assertJsonPath('favorites_preview.0.suggested_target.source', 'steam')
            ->assertJsonPath('favorites_preview.0.suggested_target.offer_kind', 'official');

        $this->assertDatabaseMissing('favorite_alerts', ['favorite_id' => $favorite->id]);
        Http::assertNothingSent();
    }
}
