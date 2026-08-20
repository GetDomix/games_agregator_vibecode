<?php

namespace Tests\Feature;

use App\Models\Favorite;
use App\Models\FavoriteAlert;
use App\Models\FavoriteAlertScope;
use App\Models\Game;
use App\Models\GamePriceObservation;
use App\Models\SiteNotification;
use App\Models\User;
use App\Services\Alerts\AlertEvaluationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SiteNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_price_alert_is_persisted_for_the_site_without_telegram_and_can_be_marked_read(): void
    {
        Queue::fake();
        $user = User::factory()->create(['telegram_chat_id' => null]);
        $game = Game::query()->create([
            'steam_appid' => 777001,
            'name' => 'Signal Game',
            'release_status' => Game::RELEASE_STATUS_RELEASED,
        ]);
        $favorite = Favorite::query()->create([
            'user_id' => $user->id,
            'game_id' => $game->id,
            'appid' => $game->steam_appid,
            'game_name' => $game->name,
        ]);
        $alert = FavoriteAlert::query()->create([
            'favorite_id' => $favorite->id,
            'condition_type' => 'target_price',
            'target_value' => 500,
            'status' => 'active',
        ]);
        FavoriteAlertScope::query()->create([
            'favorite_alert_id' => $alert->id,
            'source' => 'steam',
            'offer_kind' => 'official',
        ]);
        $observation = GamePriceObservation::query()->create([
            'game_id' => $game->id,
            'source' => 'steam',
            'offer_kind' => 'official',
            'min_price_rub' => 499,
            'observed_at' => now(),
        ]);

        $this->assertSame(1, app(AlertEvaluationService::class)->evaluate($game, 'steam', [$observation->id]));
        $notification = SiteNotification::query()->firstOrFail();
        $this->assertSame(SiteNotification::TYPE_GAME_ALERT, $notification->type);
        $this->assertSame($user->id, $notification->recipient_user_id);
        $this->assertSame($game->steam_appid, $notification->data['appid']);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/me/notifications')
            ->assertOk()
            ->assertJsonPath('unread_count', 1)
            ->assertJsonPath('items.0.title', 'Цель цены достигнута')
            ->assertJsonPath('items.0.read', false);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/me/notifications/read-through', ['through_id' => $notification->id])
            ->assertOk()
            ->assertJsonPath('read_through_id', $notification->id);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/me/notifications')
            ->assertOk()
            ->assertJsonPath('unread_count', 0)
            ->assertJsonPath('items.0.read', true);
        Queue::assertNothingPushed();
    }

    public function test_admin_broadcast_targets_only_users_existing_at_publish_time_and_is_audited(): void
    {
        $admin = User::factory()->create(['admin_role' => User::ROLE_ADMIN]);
        $recipient = User::factory()->create();
        $audienceCount = User::query()->count();

        $response = $this->actingAs($admin, 'sanctum')->postJson('/api/admin/notifications/broadcast', [
            'title' => 'Технические работы',
            'body' => 'Сегодня в 23:00 обновим каталог.',
            'priority' => 'important',
        ])->assertCreated()->assertJsonPath('audience_count', $audienceCount);

        $notificationId = (int) $response->json('id');
        $futureUser = User::factory()->create();

        $this->actingAs($recipient, 'sanctum')
            ->getJson('/api/me/notifications')
            ->assertOk()
            ->assertJsonPath('items.0.id', $notificationId)
            ->assertJsonPath('items.0.data.priority', 'important');
        $this->actingAs($futureUser, 'sanctum')
            ->getJson('/api/me/notifications')
            ->assertOk()
            ->assertJsonCount(0, 'items');
        $this->assertDatabaseHas('admin_audit_logs', [
            'actor_id' => $admin->id,
            'action' => 'notification.broadcast_sent',
            'target_id' => (string) $notificationId,
        ]);
    }

    public function test_regular_user_cannot_broadcast_notifications(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')->postJson('/api/admin/notifications/broadcast', [
            'title' => 'No access',
            'body' => 'This must not be sent.',
            'priority' => 'info',
        ])->assertForbidden();

        $this->assertDatabaseCount('site_notifications', 0);
    }

    public function test_notification_feed_can_page_back_without_growing_the_panel_payload(): void
    {
        $user = User::factory()->create();
        $notifications = collect(range(1, 3))->map(fn (int $index) => SiteNotification::query()->create([
            'type' => SiteNotification::TYPE_GAME_ALERT,
            'recipient_user_id' => $user->id,
            'title' => "Notification {$index}",
            'body' => "Body {$index}",
            'data' => [],
            'published_at' => now()->subSeconds(4 - $index),
        ]));

        $firstPage = $this->actingAs($user, 'sanctum')
            ->getJson('/api/me/notifications?limit=2')
            ->assertOk()
            ->assertJsonCount(2, 'items')
            ->assertJsonPath('has_more', true);

        $beforeId = (int) $firstPage->json('next_before_id');
        $this->assertSame($notifications[1]->id, $beforeId);

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/me/notifications?limit=2&before_id={$beforeId}")
            ->assertOk()
            ->assertJsonCount(1, 'items')
            ->assertJsonPath('items.0.id', $notifications[0]->id)
            ->assertJsonPath('has_more', false)
            ->assertJsonPath('next_before_id', null);
    }

    public function test_admin_broadcast_rejects_unknown_fields(): void
    {
        $admin = User::factory()->create(['admin_role' => User::ROLE_ADMIN]);

        $this->actingAs($admin, 'sanctum')->postJson('/api/admin/notifications/broadcast', [
            'title' => 'Strict request',
            'body' => 'Only the documented fields are accepted.',
            'priority' => 'info',
            'recipient_user_id' => User::factory()->create()->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('request');

        $this->assertDatabaseCount('site_notifications', 0);
    }
}
