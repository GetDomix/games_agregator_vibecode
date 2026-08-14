<?php

namespace Tests\Feature;

use App\Models\Favorite;
use App\Models\AlertEvent;
use App\Models\CurrentGamePrice;
use App\Models\FavoriteAlertScope;
use App\Services\Alerts\FavoriteAlertSettingsService;
use App\Models\Game;
use App\Models\GameSourceState;
use App\Models\GamePriceObservation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CrossSourceAlertSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_favorite_gets_safe_steam_default_and_accepts_market_scopes(): void
    {
        Queue::fake();
        Sanctum::actingAs($user = User::factory()->create());
        $this->postJson('/api/me/favorites', ['appid' => 42, 'game_name' => 'Scoped', 'target_price_rub' => 100])
            ->assertCreated()->assertJsonPath('alert.scopes.0.source', 'steam');
        $this->patchJson('/api/me/favorites/42', ['alert' => ['target_value' => 500, 'scopes' => [['source' => 'steam', 'offer_kind' => 'official'], ['source' => 'plati', 'offer_kind' => 'gift']]]])
            ->assertOk()->assertJsonCount(2, 'alert.scopes');
        $this->postJson('/api/me/favorites', ['appid' => 42, 'game_name' => 'Scoped'])
            ->assertOk()
            ->assertJsonCount(2, 'alert.scopes')
            ->assertJsonPath('alert.scopes.1.source', 'plati');
        $this->assertDatabaseHas('favorite_alerts', ['favorite_id' => Favorite::query()->where('user_id', $user->id)->value('id'), 'status' => 'active']);
    }

    public function test_invalid_scope_and_client_price_are_rejected(): void
    {
        Queue::fake();
        Sanctum::actingAs($user = User::factory()->create());
        $this->postJson('/api/me/favorites', ['appid' => 43, 'game_name' => 'Bad', 'last_steam_price_rub' => 10])->assertUnprocessable();
        $this->postJson('/api/me/favorites', ['appid' => 43, 'game_name' => 'Bad', 'alert' => ['scopes' => [['source' => 'plati', 'offer_kind' => 'other']]]])->assertUnprocessable();

        $this->assertDatabaseMissing('favorites', ['user_id' => $user->id, 'appid' => 43]);
        Queue::assertNothingPushed();
    }

    public function test_invalid_alert_update_does_not_partially_change_the_favorite(): void
    {
        Sanctum::actingAs($user = User::factory()->create());
        Favorite::query()->create([
            'user_id' => $user->id,
            'appid' => 45,
            'game_name' => 'Unchanged',
            'notes' => 'before',
        ]);

        $this->patchJson('/api/me/favorites/45', [
            'notes' => 'after',
            'alert' => ['scopes' => [['source' => 'steam', 'offer_kind' => 'gift']]],
        ])->assertUnprocessable();

        $this->assertDatabaseHas('favorites', [
            'user_id' => $user->id,
            'appid' => 45,
            'notes' => 'before',
        ]);
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

    public function test_discount_and_new_low_validate_their_condition_specific_contracts(): void
    {
        Queue::fake();
        Sanctum::actingAs(User::factory()->create());
        $this->postJson('/api/me/favorites', [
            'appid' => 46, 'game_name' => 'Conditions',
            'alert' => ['condition_type' => 'discount_percent', 'target_value' => 30, 'scopes' => [['source' => 'steam', 'offer_kind' => 'official']]],
        ])->assertCreated()->assertJsonPath('alert.condition_type', 'discount_percent');
        $this->getJson('/api/me/favorites')->assertJsonPath('items.0.target_price_rub', null)->assertJsonPath('items.0.price_below_target', false);
        $this->patchJson('/api/me/favorites/46', [
            'alert' => ['condition_type' => 'new_low', 'target_value' => null, 'scopes' => [['source' => 'plati', 'offer_kind' => 'gift']]],
        ])->assertOk()->assertJsonPath('alert.condition_type', 'new_low')->assertJsonPath('alert.target_value', null);
        $this->getJson('/api/me/favorites')->assertJsonPath('items.0.target_price_rub', null)->assertJsonPath('items.0.price_below_target', false);
        $this->patchJson('/api/me/favorites/46', [
            'alert' => ['condition_type' => 'discount_percent', 'target_value' => 30, 'scopes' => [['source' => 'plati', 'offer_kind' => 'gift']]],
        ])->assertUnprocessable();
        $this->patchJson('/api/me/favorites/46', [
            'alert' => ['condition_type' => 'discount_percent', 'target_value' => 1, 'scopes' => [['source' => 'steam', 'offer_kind' => 'official']]],
        ])->assertOk();
        $this->patchJson('/api/me/favorites/46', [
            'alert' => ['condition_type' => 'discount_percent', 'target_value' => 100, 'scopes' => [['source' => 'steam', 'offer_kind' => 'official']]],
        ])->assertOk();
        foreach ([0, 101] as $invalid) {
            $this->patchJson('/api/me/favorites/46', [
                'alert' => ['condition_type' => 'discount_percent', 'target_value' => $invalid, 'scopes' => [['source' => 'steam', 'offer_kind' => 'official']]],
            ])->assertUnprocessable();
        }
    }

    public function test_favorite_suggestion_is_top_level_non_persistent_and_stored_only(): void
    {
        Http::fake();
        $user = User::factory()->create();
        $game = Game::query()->create(['steam_appid' => 47, 'name' => 'Hint', 'release_status' => 'released']);
        $favorite = Favorite::query()->create(['user_id' => $user->id, 'game_id' => $game->id, 'appid' => 47, 'game_name' => 'Hint']);
        $alert = $favorite->alert()->create(['condition_type' => 'target_price', 'target_value' => null]);
        FavoriteAlertScope::query()->create(['favorite_alert_id' => $alert->id, 'source' => 'steam', 'offer_kind' => 'official']);
        CurrentGamePrice::query()->create(['game_id' => $game->id, 'source' => 'steam', 'offer_kind' => 'official', 'min_price_rub' => 1000, 'observed_at' => now()]);
        GamePriceObservation::query()->create(['game_id' => $game->id, 'source' => 'steam', 'offer_kind' => 'official', 'min_price_rub' => 1200, 'observed_at' => now()->subDay()]);
        GamePriceObservation::query()->create(['game_id' => $game->id, 'source' => 'steam', 'offer_kind' => 'official', 'min_price_rub' => 950, 'observed_at' => now()]);
        Sanctum::actingAs($user);

        $this->getJson('/api/me/favorites')->assertOk()
            ->assertJsonPath('items.0.suggested_target.value_rub', 900)
            ->assertJsonPath('items.0.suggested_target.basis', 'current_price_minus_10_percent')
            ->assertJsonPath('items.0.observed_lows.0.price_rub', 950)
            ->assertJsonPath('items.0.observed_lows.0.source', 'steam')
            ->assertJsonMissingPath('items.0.alert.suggested_target');
        $this->assertDatabaseHas('favorite_alerts', ['favorite_id' => $favorite->id, 'target_value' => null]);
        Http::assertNothingSent();
    }

    public function test_save_cycles_on_semantic_change_but_not_reordered_scopes(): void
    {
        $favorite = Favorite::query()->create(['user_id' => User::factory()->create()->id, 'appid' => 48, 'game_name' => 'Cycles']);
        $service = app(FavoriteAlertSettingsService::class);
        $first = $service->save($favorite, ['target_value' => 100, 'scopes' => [['source' => 'steam', 'offer_kind' => 'official'], ['source' => 'plati', 'offer_kind' => 'gift']]]);
        $first->update(['status' => 'triggered']);
        $same = $service->save($favorite, ['target_value' => 100, 'scopes' => [['source' => 'plati', 'offer_kind' => 'gift'], ['source' => 'steam', 'offer_kind' => 'official']]]);
        $this->assertSame(0, $same->cycle);
        $changedTarget = $service->save($favorite, ['target_value' => 99, 'scopes' => [['source' => 'steam', 'offer_kind' => 'official'], ['source' => 'plati', 'offer_kind' => 'gift']]]);
        $this->assertSame(1, $changedTarget->cycle);
        $this->assertSame('active', $changedTarget->status);
        $changedScopes = $service->save($favorite, ['target_value' => 99, 'scopes' => [['source' => 'steam', 'offer_kind' => 'official']]]);
        $this->assertSame(2, $changedScopes->cycle);
        $changed = $service->save($favorite, ['condition_type' => 'new_low', 'target_value' => null, 'scopes' => [['source' => 'steam', 'offer_kind' => 'official']]]);
        $this->assertSame(3, $changed->cycle);
        $this->assertSame('active', $changed->status);
    }

    public function test_plain_and_legacy_favorite_alert_compatibility(): void
    {
        Queue::fake(); Sanctum::actingAs($user = User::factory()->create());
        $this->postJson('/api/me/favorites', ['appid' => 49, 'game_name' => 'Plain'])->assertCreated()->assertJsonPath('alert', null);
        $this->assertDatabaseMissing('favorite_alerts', ['favorite_id' => Favorite::query()->where('appid', 49)->value('id')]);
        $this->postJson('/api/me/favorites', ['appid' => 50, 'game_name' => 'Legacy', 'target_price_rub' => 500])->assertCreated()->assertJsonPath('alert.condition_type', 'target_price')->assertJsonPath('alert.target_value', 500);
        $this->postJson('/api/me/favorites', ['appid' => 51, 'game_name' => 'Bad', 'alert' => ['condition_type' => 'target_price', 'target_value' => null]])->assertUnprocessable();
        $this->postJson('/api/me/favorites', ['appid' => 52, 'game_name' => 'Zero', 'alert' => ['condition_type' => 'target_price', 'target_value' => 0]])->assertUnprocessable();

        $existing = Favorite::query()->where('appid', 50)->firstOrFail();
        $existing->alert()->update(['status' => 'triggered', 'cycle' => 4]);
        $this->postJson('/api/me/favorites', ['appid' => 50, 'game_name' => 'Legacy renamed'])->assertOk();
        $this->assertDatabaseHas('favorite_alerts', [
            'favorite_id' => $existing->id,
            'condition_type' => 'target_price',
            'target_value' => 500,
            'status' => 'triggered',
            'cycle' => 4,
        ]);
        $this->postJson('/api/me/favorites', ['appid' => 54, 'game_name' => 'Null alert', 'alert' => null])->assertUnprocessable();
        $this->patchJson('/api/me/favorites/50', ['alert' => null])->assertUnprocessable();
    }

    public function test_legacy_target_patch_projects_bidirectionally_without_touching_non_target_alerts(): void
    {
        Sanctum::actingAs($user = User::factory()->create());
        Favorite::query()->create(['user_id' => $user->id, 'appid' => 55, 'game_name' => 'Legacy patch']);
        $this->patchJson('/api/me/favorites/55', ['target_price_rub' => 600])
            ->assertOk()->assertJsonPath('target_price_rub', 600)->assertJsonPath('alert.condition_type', 'target_price');
        $this->patchJson('/api/me/favorites/55', ['alert' => ['condition_type' => 'discount_percent', 'target_value' => 30, 'scopes' => [['source' => 'steam', 'offer_kind' => 'official']]]])
            ->assertOk()->assertJsonPath('target_price_rub', null);
        $this->patchJson('/api/me/favorites/55', ['target_price_rub' => null])->assertOk()->assertJsonPath('alert.condition_type', 'discount_percent');
        $this->patchJson('/api/me/favorites/55', ['alert' => ['condition_type' => 'target_price', 'target_value' => 500]])->assertOk();
        $this->patchJson('/api/me/favorites/55', ['target_price_rub' => null])->assertOk()->assertJsonPath('alert', null)->assertJsonPath('target_price_rub', null);
    }

    public function test_plain_favorite_gets_non_persistent_default_steam_suggestion(): void
    {
        Http::fake();
        $user = User::factory()->create();
        $game = Game::query()->create(['steam_appid' => 53, 'name' => 'Plain hint', 'release_status' => 'released']);
        $favorite = Favorite::query()->create(['user_id' => $user->id, 'game_id' => $game->id, 'appid' => 53, 'game_name' => 'Plain hint']);
        CurrentGamePrice::query()->create(['game_id' => $game->id, 'source' => 'steam', 'offer_kind' => 'official', 'min_price_rub' => 1000, 'observed_at' => now()]);
        Sanctum::actingAs($user);

        $this->getJson('/api/me/favorites')->assertOk()
            ->assertJsonPath('items.0.alert', null)
            ->assertJsonPath('items.0.suggested_target.value_rub', 900)
            ->assertJsonPath('items.0.suggested_target.source', 'steam')
            ->assertJsonPath('items.0.suggested_target.offer_kind', 'official');
        $this->assertDatabaseMissing('favorite_alerts', ['favorite_id' => $favorite->id]);
        Http::assertNothingSent();
    }

    public function test_forward_cleanup_removes_only_invalid_legacy_null_target_alerts_and_api_never_projects_zero(): void
    {
        $user = User::factory()->create();
        $plain = Favorite::query()->create(['user_id' => $user->id, 'appid' => 56, 'game_name' => 'Old plain']);
        $plain->alert()->create(['condition_type' => 'target_price', 'target_value' => null, 'status' => 'triggered']);
        $legacyOnly = Favorite::query()->create(['user_id' => $user->id, 'appid' => 59, 'game_name' => 'Old target only', 'target_price_rub' => 650]);
        $priced = Favorite::query()->create(['user_id' => $user->id, 'appid' => 57, 'game_name' => 'Old priced', 'target_price_rub' => 700]);
        $priced->alert()->create(['condition_type' => 'target_price', 'target_value' => 700]);
        $newLow = Favorite::query()->create(['user_id' => $user->id, 'appid' => 58, 'game_name' => 'New low']);
        $newLow->alert()->create(['condition_type' => 'new_low', 'target_value' => null]);

        $migration = require database_path('migrations/2026_08_13_120100_remove_invalid_legacy_target_alerts.php');
        $migration->up();

        $this->assertDatabaseMissing('favorite_alerts', ['favorite_id' => $plain->id]);
        $this->assertDatabaseMissing('favorite_alerts', ['favorite_id' => $legacyOnly->id]);
        $this->assertDatabaseHas('favorites', ['id' => $legacyOnly->id, 'target_price_rub' => null]);
        $this->assertDatabaseHas('favorite_alerts', ['favorite_id' => $priced->id, 'condition_type' => 'target_price', 'target_value' => 700]);
        $this->assertDatabaseHas('favorite_alerts', ['favorite_id' => $newLow->id, 'condition_type' => 'new_low', 'target_value' => null]);

        // Projection remains safe even before a rolling deploy's cleanup runs.
        $plain->alert()->create(['condition_type' => 'target_price', 'target_value' => null, 'status' => 'triggered']);
        Sanctum::actingAs($user);
        $this->getJson('/api/me/favorites')->assertOk()
            ->assertJsonPath('items.0.target_price_rub', null)
            ->assertJsonPath('items.0.price_below_target', false);
    }

    public function test_forward_cycle_repair_changes_only_active_alerts_with_occupied_current_generation(): void
    {
        $user = User::factory()->create();
        $game = Game::query()->create(['steam_appid' => 60, 'name' => 'Cycle repair', 'release_status' => 'released']);
        $activeFavorite = Favorite::query()->create(['user_id' => $user->id, 'game_id' => $game->id, 'appid' => 60, 'game_name' => 'Cycle repair']);
        $active = $activeFavorite->alert()->create(['condition_type' => 'target_price', 'target_value' => 100, 'status' => 'active', 'cycle' => 0]);
        AlertEvent::query()->create(['favorite_alert_id' => $active->id, 'alert_cycle' => 0, 'user_id' => $user->id, 'favorite_id' => $activeFavorite->id, 'game_id' => $game->id, 'source' => 'steam', 'offer_kind' => 'official', 'offer_price_rub' => 100, 'observed_at' => now()]);
        $triggeredFavorite = Favorite::query()->create(['user_id' => $user->id, 'game_id' => $game->id, 'appid' => 61, 'game_name' => 'Triggered']);
        $triggered = $triggeredFavorite->alert()->create(['condition_type' => 'target_price', 'target_value' => 100, 'status' => 'triggered', 'cycle' => 0]);
        AlertEvent::query()->create(['favorite_alert_id' => $triggered->id, 'alert_cycle' => 0, 'user_id' => $user->id, 'favorite_id' => $triggeredFavorite->id, 'game_id' => $game->id, 'source' => 'steam', 'offer_kind' => 'official', 'offer_price_rub' => 100, 'observed_at' => now()]);

        (require database_path('migrations/2026_08_13_120200_repair_active_alert_cycles_occupied_by_events.php'))->up();

        $this->assertDatabaseHas('favorite_alerts', ['id' => $active->id, 'status' => 'active', 'cycle' => 1]);
        $this->assertDatabaseHas('favorite_alerts', ['id' => $triggered->id, 'status' => 'triggered', 'cycle' => 0]);
    }
}
