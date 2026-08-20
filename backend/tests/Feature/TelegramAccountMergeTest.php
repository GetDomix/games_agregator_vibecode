<?php

namespace Tests\Feature;

use App\Models\AlertDelivery;
use App\Models\AlertEvent;
use App\Models\ExternalIdentity;
use App\Models\Favorite;
use App\Models\FavoriteAlert;
use App\Models\FavoriteAlertScope;
use App\Models\Game;
use App\Models\GamePriceObservation;
use App\Models\SiteNotification;
use App\Models\User;
use App\Services\Alerts\AlertEvaluationService;
use App\Services\Alerts\FavoriteAlertSettingsService;
use App\Services\Telegram\TelegramAccountMergeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
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
        $this->assertDatabaseMissing('favorite_alert_scopes', [
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
        $websiteNotification = $this->siteNotification($websiteEvent, $site, 'Website signal');
        $telegramNotification = $this->siteNotification($telegramEvent, $telegram, 'Telegram signal');
        $site->forceFill(['notifications_read_through_id' => $websiteNotification->id])->save();

        app(TelegramAccountMergeService::class)->merge($site, $telegram);

        $this->assertDatabaseCount('alert_events', 2);
        $this->assertDatabaseHas('alert_events', [
            'id' => $websiteEvent->id,
            'favorite_alert_id' => $websiteAlert->id,
            'alert_cycle' => 2,
            'user_id' => $site->id,
            'favorite_id' => $websiteFavorite->id,
        ]);
        $this->assertDatabaseHas('alert_events', [
            'id' => $telegramEvent->id,
            'favorite_alert_id' => $websiteAlert->id,
            'alert_cycle' => 0,
            'user_id' => $site->id,
            'favorite_id' => $websiteFavorite->id,
        ]);
        $this->assertDatabaseHas('alert_deliveries', ['id' => $websiteDelivery->id, 'alert_event_id' => $websiteEvent->id]);
        $this->assertDatabaseHas('alert_deliveries', ['id' => $telegramDelivery->id, 'alert_event_id' => $telegramEvent->id, 'status' => 'failed']);
        $this->assertDatabaseHas('site_notifications', ['id' => $websiteNotification->id, 'recipient_user_id' => $site->id]);
        $this->assertDatabaseHas('site_notifications', ['id' => $telegramNotification->id, 'recipient_user_id' => $site->id]);
        $this->assertDatabaseHas('users', ['id' => $site->id, 'notifications_read_through_id' => 0]);
        $this->assertDatabaseHas('favorite_alerts', ['id' => $websiteAlert->id, 'cycle' => 2, 'status' => 'triggered', 'triggered_at' => null, 'condition_type' => 'target_price', 'target_value' => 500]);
        app(FavoriteAlertSettingsService::class)->rearm($websiteFavorite->fresh());
        $this->assertDatabaseHas('favorite_alerts', ['id' => $websiteAlert->id, 'cycle' => 3, 'status' => 'active']);
        $this->alertEvent($websiteAlert->fresh(), $site, $websiteFavorite, $game, 3, 200);
        $this->assertDatabaseHas('alert_events', ['favorite_alert_id' => $websiteAlert->id, 'alert_cycle' => 3]);
        $this->assertDatabaseMissing('favorite_alerts', ['id' => $telegramAlert->id]);
    }

    public function test_existing_condition_aware_alert_wins_without_mixing_source_settings(): void
    {
        foreach ([['discount_percent', 35], ['new_low', null]] as [$condition, $target]) {
            $site = User::factory()->create();
            $telegram = User::factory()->create(['telegram_chat_id' => (string) random_int(900000, 999999)]);
            $siteFavorite = Favorite::query()->create(['user_id' => $site->id, 'appid' => random_int(100000, 199999), 'game_name' => 'Same']);
            $sourceFavorite = Favorite::query()->create(['user_id' => $telegram->id, 'appid' => $siteFavorite->appid, 'game_name' => 'Same', 'target_price_rub' => 400]);
            $siteAlert = $siteFavorite->alert()->create(['condition_type' => $condition, 'target_value' => $target, 'status' => 'active', 'cycle' => 7]);
            $sourceAlert = $sourceFavorite->alert()->create(['condition_type' => 'target_price', 'target_value' => 400, 'status' => 'triggered', 'cycle' => 2]);
            FavoriteAlertScope::query()->create(['favorite_alert_id' => $siteAlert->id, 'source' => 'steam', 'offer_kind' => 'official']);
            FavoriteAlertScope::query()->create(['favorite_alert_id' => $sourceAlert->id, 'source' => 'plati', 'offer_kind' => 'gift']);

            app(TelegramAccountMergeService::class)->merge($site, $telegram);

            $this->assertDatabaseHas('favorite_alerts', ['id' => $siteAlert->id, 'condition_type' => $condition, 'target_value' => $target, 'status' => 'active', 'cycle' => 7]);
            $this->assertDatabaseHas('favorites', ['id' => $siteFavorite->id, 'target_price_rub' => null]);
            $this->assertDatabaseMissing('favorite_alert_scopes', ['favorite_alert_id' => $siteAlert->id, 'source' => 'plati', 'offer_kind' => 'gift']);
        }
    }

    public function test_active_target_alert_reserves_an_unoccupied_cycle_after_importing_history(): void
    {
        Queue::fake();
        $site = User::factory()->create();
        $telegram = User::factory()->create(['telegram_chat_id' => '445']);
        $game = Game::query()->create(['steam_appid' => 445, 'name' => 'Collision safe', 'release_status' => 'released']);
        $target = Favorite::query()->create(['user_id' => $site->id, 'game_id' => $game->id, 'appid' => 445, 'game_name' => $game->name]);
        $source = Favorite::query()->create(['user_id' => $telegram->id, 'game_id' => $game->id, 'appid' => 445, 'game_name' => $game->name]);
        $targetAlert = $target->alert()->create(['condition_type' => 'target_price', 'target_value' => 100, 'status' => 'active', 'cycle' => 0]);
        $sourceAlert = $source->alert()->create(['condition_type' => 'target_price', 'target_value' => 80, 'status' => 'triggered', 'cycle' => 0]);
        FavoriteAlertScope::query()->create(['favorite_alert_id' => $targetAlert->id, 'source' => 'steam', 'offer_kind' => 'official']);
        // Simulate the old merge bug: active current cycle is already occupied.
        $this->alertEvent($targetAlert, $site, $target, $game, 0, 100);
        $sourceEvent = $this->alertEvent($sourceAlert, $telegram, $source, $game, 0, 80);
        AlertDelivery::query()->create(['alert_event_id' => $sourceEvent->id, 'status' => AlertDelivery::STATUS_SENT]);

        app(TelegramAccountMergeService::class)->merge($site, $telegram);

        $this->assertDatabaseHas('favorite_alerts', ['id' => $targetAlert->id, 'status' => 'active', 'cycle' => 2, 'condition_type' => 'target_price', 'target_value' => 100]);
        $this->assertDatabaseHas('alert_events', ['id' => $sourceEvent->id, 'favorite_alert_id' => $targetAlert->id, 'alert_cycle' => 0]);
        $fresh = GamePriceObservation::query()->create(['game_id' => $game->id, 'source' => 'steam', 'offer_kind' => 'official', 'min_price_rub' => 99, 'observed_at' => now()]);
        $this->assertSame(1, app(AlertEvaluationService::class)->evaluate($game, 'steam', [$fresh->id]));
        $this->assertDatabaseHas('alert_events', ['favorite_alert_id' => $targetAlert->id, 'alert_cycle' => 2, 'offer_price_rub' => 99]);
    }

    public function test_source_alert_transfers_whole_when_duplicate_target_has_no_alert(): void
    {
        $site = User::factory()->create();
        $telegram = User::factory()->create(['telegram_chat_id' => '777777']);
        $target = Favorite::query()->create(['user_id' => $site->id, 'appid' => 777, 'game_name' => 'Transfer']);
        $source = Favorite::query()->create(['user_id' => $telegram->id, 'appid' => 777, 'game_name' => 'Transfer']);
        $sourceAlert = $source->alert()->create(['condition_type' => 'new_low', 'target_value' => null, 'status' => 'active', 'cycle' => 4]);
        FavoriteAlertScope::query()->create(['favorite_alert_id' => $sourceAlert->id, 'source' => 'plati', 'offer_kind' => 'gift']);

        app(TelegramAccountMergeService::class)->merge($site, $telegram);

        $targetAlert = FavoriteAlert::query()->where('favorite_id', $target->id)->firstOrFail();
        $this->assertSame('new_low', $targetAlert->condition_type);
        $this->assertNull($targetAlert->target_value);
        $this->assertSame(4, $targetAlert->cycle);
        $this->assertDatabaseHas('favorite_alert_scopes', ['favorite_alert_id' => $targetAlert->id, 'source' => 'plati', 'offer_kind' => 'gift']);
        $this->assertDatabaseHas('favorites', ['id' => $target->id, 'target_price_rub' => null]);
    }

    public function test_transferred_target_price_alert_synchronizes_legacy_projection(): void
    {
        $site = User::factory()->create();
        $telegram = User::factory()->create(['telegram_chat_id' => '888888']);
        $target = Favorite::query()->create(['user_id' => $site->id, 'appid' => 888, 'game_name' => 'Projection', 'target_price_rub' => 500]);
        $source = Favorite::query()->create(['user_id' => $telegram->id, 'appid' => 888, 'game_name' => 'Projection']);
        $source->alert()->create(['condition_type' => 'target_price', 'target_value' => 300, 'status' => 'active']);

        app(TelegramAccountMergeService::class)->merge($site, $telegram);

        $this->assertDatabaseHas('favorites', ['id' => $target->id, 'target_price_rub' => 300]);
        $this->assertDatabaseHas('favorite_alerts', ['favorite_id' => $target->id, 'condition_type' => 'target_price', 'target_value' => 300]);
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

    private function siteNotification(AlertEvent $event, User $recipient, string $title): SiteNotification
    {
        return SiteNotification::query()->create([
            'type' => SiteNotification::TYPE_GAME_ALERT,
            'recipient_user_id' => $recipient->id,
            'alert_event_id' => $event->id,
            'title' => $title,
            'body' => 'Preserved after account merge.',
            'data' => ['appid' => $event->game_id],
            'published_at' => now(),
        ]);
    }
}
