<?php

namespace Tests\Feature;

use App\Models\AlertDelivery;
use App\Models\AlertEvent;
use App\Models\ExternalIdentity;
use App\Models\Favorite;
use App\Models\FavoriteAlert;
use App\Models\FavoriteAlertScope;
use App\Models\Game;
use App\Models\User;
use App\Services\TelegramAccountMergeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TelegramAccountMergeTest extends TestCase
{
    use RefreshDatabase;

    public function test_merge_moves_unique_favorites_and_keeps_website_target(): void
    {
        $site = User::factory()->create();
        $telegram = User::factory()->create([
            'telegram_chat_id' => '123456',
            'telegram_username' => 'radar_user',
            'radar_enabled' => true,
        ]);
        $websiteFavorite = Favorite::query()->create([
            'user_id' => $site->id,
            'appid' => 1,
            'game_name' => 'One',
            'target_price_rub' => 300,
        ]);
        $telegramFavorite = Favorite::query()->create([
            'user_id' => $telegram->id,
            'appid' => 1,
            'game_name' => 'One',
            'target_price_rub' => 100,
        ]);
        $uniqueFavorite = Favorite::query()->create([
            'user_id' => $telegram->id,
            'appid' => 2,
            'game_name' => 'Two',
        ]);

        $websiteAlert = FavoriteAlert::query()->create([
            'favorite_id' => $websiteFavorite->id,
            'condition_type' => 'target_price',
            'target_value' => 300,
            'status' => 'active',
        ]);
        $telegramAlert = FavoriteAlert::query()->create([
            'favorite_id' => $telegramFavorite->id,
            'condition_type' => 'target_price',
            'target_value' => 100,
            'status' => 'active',
        ]);
        FavoriteAlertScope::query()->create([
            'favorite_alert_id' => $websiteAlert->id,
            'source' => 'steam',
            'offer_kind' => 'official',
        ]);
        FavoriteAlertScope::query()->create([
            'favorite_alert_id' => $telegramAlert->id,
            'source' => 'plati',
            'offer_kind' => 'gift',
        ]);

        app(TelegramAccountMergeService::class)->merge($site, $telegram);

        $this->assertDatabaseCount('favorites', 2);
        $this->assertDatabaseHas('favorites', [
            'user_id' => $site->id,
            'appid' => 1,
            'target_price_rub' => 300,
        ]);
        $this->assertDatabaseHas('favorites', ['id' => $uniqueFavorite->id, 'user_id' => $site->id]);
        $this->assertDatabaseHas('favorite_alert_scopes', [
            'favorite_alert_id' => $websiteAlert->id,
            'source' => 'plati',
            'offer_kind' => 'gift',
        ]);
        $this->assertDatabaseHas('users', ['id' => $site->id, 'telegram_chat_id' => '123456']);
        $this->assertDatabaseMissing('users', ['id' => $telegram->id]);
    }

    public function test_merge_is_a_no_op_for_the_same_account(): void
    {
        $user = User::factory()->create(['telegram_chat_id' => '123']);

        $this->assertSame(['favorites_moved' => 0], app(TelegramAccountMergeService::class)->merge($user, $user));
        $this->assertDatabaseHas('users', ['id' => $user->id, 'telegram_chat_id' => '123']);
    }

    public function test_merge_rejects_conflicting_existing_telegram_links_without_partial_changes(): void
    {
        $site = User::factory()->create(['telegram_chat_id' => '111']);
        $telegram = User::factory()->create(['telegram_chat_id' => '222']);
        $favorite = Favorite::query()->create(['user_id' => $telegram->id, 'appid' => 3, 'game_name' => 'Three']);

        try {
            app(TelegramAccountMergeService::class)->merge($site, $telegram);
            $this->fail('Expected a conflicting Telegram link to abort the merge.');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('already linked', $error->getMessage());
        }

        $this->assertDatabaseHas('users', ['id' => $site->id, 'telegram_chat_id' => '111']);
        $this->assertDatabaseHas('users', ['id' => $telegram->id, 'telegram_chat_id' => '222']);
        $this->assertDatabaseHas('favorites', ['id' => $favorite->id, 'user_id' => $telegram->id]);
    }

    public function test_merge_moves_identity_and_account_history_before_deleting_duplicate(): void
    {
        $site = User::factory()->create();
        $telegram = User::factory()->create(['telegram_chat_id' => '333']);
        $identity = ExternalIdentity::query()->create([
            'user_id' => $telegram->id,
            'provider' => 'telegram',
            'provider_subject' => '333',
        ]);
        $historyId = DB::table('search_histories')->insertGetId([
            'user_id' => $telegram->id,
            'query' => 'Preserved search',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        app(TelegramAccountMergeService::class)->merge($site, $telegram);

        $this->assertDatabaseHas('external_identities', ['id' => $identity->id, 'user_id' => $site->id]);
        $this->assertDatabaseHas('search_histories', ['id' => $historyId, 'user_id' => $site->id]);
        $this->assertDatabaseMissing('users', ['id' => $telegram->id]);
    }

    public function test_merge_preserves_duplicate_favorite_alert_history_and_deliveries(): void
    {
        $site = User::factory()->create();
        $telegram = User::factory()->create(['telegram_chat_id' => '444']);
        $game = Game::query()->create(['steam_appid' => 44, 'name' => 'History', 'release_status' => 'released']);
        $websiteFavorite = Favorite::query()->create([
            'user_id' => $site->id,
            'game_id' => $game->id,
            'appid' => 44,
            'game_name' => 'History',
        ]);
        $telegramFavorite = Favorite::query()->create([
            'user_id' => $telegram->id,
            'game_id' => $game->id,
            'appid' => 44,
            'game_name' => 'History',
        ]);
        $websiteAlert = FavoriteAlert::query()->create([
            'favorite_id' => $websiteFavorite->id,
            'condition_type' => 'target_price',
            'target_value' => 500,
            'status' => 'triggered',
            'cycle' => 1,
        ]);
        $telegramAlert = FavoriteAlert::query()->create([
            'favorite_id' => $telegramFavorite->id,
            'condition_type' => 'target_price',
            'target_value' => 300,
            'status' => 'triggered',
            'cycle' => 1,
        ]);
        $websiteEvent = $this->alertEvent($websiteAlert, $site, $websiteFavorite, $game, 1, 450);
        $telegramEvent = $this->alertEvent($telegramAlert, $telegram, $telegramFavorite, $game, 1, 250);
        $websiteDelivery = AlertDelivery::query()->create([
            'alert_event_id' => $websiteEvent->id,
            'status' => AlertDelivery::STATUS_SENT,
            'attempts' => 1,
            'sent_at' => now(),
        ]);
        $telegramDelivery = AlertDelivery::query()->create([
            'alert_event_id' => $telegramEvent->id,
            'status' => AlertDelivery::STATUS_FAILED,
            'attempts' => 2,
            'last_error' => 'temporary',
        ]);

        app(TelegramAccountMergeService::class)->merge($site, $telegram);

        $this->assertDatabaseCount('alert_events', 2);
        $this->assertDatabaseHas('alert_events', [
            'id' => $websiteEvent->id,
            'favorite_alert_id' => $websiteAlert->id,
            'alert_cycle' => 1,
            'user_id' => $site->id,
            'favorite_id' => $websiteFavorite->id,
        ]);
        $this->assertDatabaseHas('alert_events', [
            'id' => $telegramEvent->id,
            'favorite_alert_id' => $websiteAlert->id,
            'alert_cycle' => 2,
            'user_id' => $site->id,
            'favorite_id' => $websiteFavorite->id,
        ]);
        $this->assertDatabaseHas('alert_deliveries', ['id' => $websiteDelivery->id, 'alert_event_id' => $websiteEvent->id]);
        $this->assertDatabaseHas('alert_deliveries', ['id' => $telegramDelivery->id, 'alert_event_id' => $telegramEvent->id, 'status' => 'failed']);
        $this->assertDatabaseHas('favorite_alerts', ['id' => $websiteAlert->id, 'cycle' => 2]);
        $this->assertDatabaseMissing('favorite_alerts', ['id' => $telegramAlert->id]);
    }

    private function alertEvent(
        FavoriteAlert $alert,
        User $user,
        Favorite $favorite,
        Game $game,
        int $cycle,
        float $price
    ): AlertEvent {
        return AlertEvent::query()->create([
            'favorite_alert_id' => $alert->id,
            'alert_cycle' => $cycle,
            'user_id' => $user->id,
            'favorite_id' => $favorite->id,
            'game_id' => $game->id,
            'source' => 'steam',
            'offer_kind' => 'official',
            'offer_price_rub' => $price,
            'observed_at' => now(),
        ]);
    }
}
