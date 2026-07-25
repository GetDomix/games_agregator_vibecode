<?php

namespace Tests\Feature;

use App\Models\Favorite;
use App\Models\Game;
use App\Models\GameSourceState;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CrossSourceAlertSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_favorite_gets_safe_steam_default_and_accepts_market_scopes(): void
    {
        Queue::fake();
        Sanctum::actingAs($user = User::factory()->create());
        $this->postJson('/api/me/favorites', ['appid' => 42, 'game_name' => 'Scoped'])
            ->assertCreated()->assertJsonPath('alert.scopes.0.source', 'steam');
        $this->patchJson('/api/me/favorites/42', ['alert' => ['target_value' => 500, 'scopes' => [['source' => 'steam', 'offer_kind' => 'official'], ['source' => 'plati', 'offer_kind' => 'gift']]]])
            ->assertOk()->assertJsonCount(2, 'alert.scopes');
        $this->assertDatabaseHas('favorite_alerts', ['favorite_id' => Favorite::query()->where('user_id', $user->id)->value('id'), 'status' => 'active']);
    }

    public function test_invalid_scope_and_client_price_are_rejected(): void
    {
        Queue::fake();
        Sanctum::actingAs(User::factory()->create());
        $this->postJson('/api/me/favorites', ['appid' => 43, 'game_name' => 'Bad', 'last_steam_price_rub' => 10])->assertUnprocessable();
        $this->postJson('/api/me/favorites', ['appid' => 43, 'game_name' => 'Bad', 'alert' => ['scopes' => [['source' => 'plati', 'offer_kind' => 'other']]]])->assertUnprocessable();
    }

    public function test_favorite_list_exposes_release_and_source_freshness_without_store_requests(): void
    {
        $user = User::factory()->create();
        $game = Game::query()->create(['steam_appid' => 44, 'name' => 'Announced', 'release_status' => 'announced']);
        Favorite::query()->create(['user_id' => $user->id, 'game_id' => $game->id, 'appid' => 44, 'game_name' => 'Announced']);
        GameSourceState::query()->create(['game_id' => $game->id, 'source' => 'steam', 'status' => 'fresh', 'last_success_at' => now()]);
        GameSourceState::query()->create(['game_id' => $game->id, 'source' => 'plati', 'status' => 'pending']);

        Sanctum::actingAs($user);
        $this->getJson('/api/me/favorites')
            ->assertOk()
            ->assertJsonPath('items.0.release_status', 'announced')
            ->assertJsonPath('items.0.freshness.0.source', 'steam')
            ->assertJsonPath('items.0.freshness.1.status', 'pending');
    }
}
