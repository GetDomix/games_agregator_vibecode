<?php

namespace Tests\Feature;

use App\Jobs\DeliverAlertEventJob;
use App\Models\AlertDelivery;
use App\Models\AlertEvent;
use App\Models\CurrentGamePrice;
use App\Models\Favorite;
use App\Models\FavoriteAlert;
use App\Models\FavoriteAlertScope;
use App\Models\Game;
use App\Models\User;
use App\Services\AlertEvaluationService;
use App\Services\FavoriteAlertSettingsService;
use App\Services\TelegramNotifyService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery\MockInterface;
use Tests\TestCase;

class AlertEvaluationDeliveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_selected_source_and_offer_kind_can_trigger_target(): void
    {
        Queue::fake();
        [$alert, $game] = $this->activeAlert(100, true);
        FavoriteAlertScope::query()->create(['favorite_alert_id' => $alert->id, 'source' => 'plati', 'offer_kind' => 'gift']);
        $this->price($game, 'steam', 'official', 50);
        $this->price($game, 'plati', 'key', 70);
        $this->price($game, 'plati', 'gift', 90, 'Gift offer', 'https://example.test/gift');

        $created = app(AlertEvaluationService::class)->evaluate($game);

        $this->assertSame(1, $created);
        $this->assertDatabaseHas('alert_events', [
            'favorite_alert_id' => $alert->id,
            'source' => 'plati',
            'offer_kind' => 'gift',
            'offer_price_rub' => 90,
        ]);
        $this->assertDatabaseHas('favorite_alerts', ['id' => $alert->id, 'status' => 'triggered']);
        Queue::assertPushed(DeliverAlertEventJob::class);
    }

    public function test_duplicate_evaluation_creates_one_event_and_rearm_starts_a_new_cycle(): void
    {
        Queue::fake();
        [$alert, $game] = $this->activeAlert(100);
        FavoriteAlertScope::query()->create(['favorite_alert_id' => $alert->id, 'source' => 'steam', 'offer_kind' => 'official']);
        $this->price($game, 'steam', 'official', 100);

        $evaluator = app(AlertEvaluationService::class);
        $this->assertSame(1, $evaluator->evaluate($game));
        $this->assertSame(0, $evaluator->evaluate($game));
        $this->assertDatabaseCount('alert_events', 1);

        app(FavoriteAlertSettingsService::class)->rearm($alert->refresh()->favorite);
        $this->assertSame(1, $evaluator->evaluate($game));
        $this->assertDatabaseCount('alert_events', 2);
        $this->assertDatabaseHas('alert_events', ['favorite_alert_id' => $alert->id, 'alert_cycle' => 1]);
    }

    public function test_failed_delivery_keeps_the_same_event_and_can_be_retried(): void
    {
        Queue::fake();
        [$alert, $game] = $this->activeAlert(100, true);
        FavoriteAlertScope::query()->create(['favorite_alert_id' => $alert->id, 'source' => 'steam', 'offer_kind' => 'official']);
        $this->price($game, 'steam', 'official', 99);
        app(AlertEvaluationService::class)->evaluate($game);
        $event = AlertEvent::query()->firstOrFail();

        $this->mock(TelegramNotifyService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('sendMessage')->once()->andReturnFalse();
        });
        $job = new DeliverAlertEventJob($event->id);
        try {
            $job->handle(app(TelegramNotifyService::class));
        } catch (\RuntimeException $exception) {
            $job->failed($exception);
        }

        $this->assertDatabaseHas('alert_deliveries', ['alert_event_id' => $event->id, 'status' => 'failed', 'attempts' => 1]);
        $this->assertDatabaseCount('alert_events', 1);

        $this->mock(TelegramNotifyService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('sendMessage')->once()->andReturnTrue();
        });
        (new DeliverAlertEventJob($event->id))->handle(app(TelegramNotifyService::class));

        $this->assertDatabaseHas('alert_deliveries', ['alert_event_id' => $event->id, 'status' => 'sent', 'attempts' => 2]);
    }

    public function test_successful_delivery_replay_does_not_send_twice(): void
    {
        Queue::fake();
        [$alert, $game] = $this->activeAlert(100, true);
        FavoriteAlertScope::query()->create(['favorite_alert_id' => $alert->id, 'source' => 'steam', 'offer_kind' => 'official']);
        $this->price($game, 'steam', 'official', 99);
        app(AlertEvaluationService::class)->evaluate($game);
        $event = AlertEvent::query()->firstOrFail();

        $this->mock(TelegramNotifyService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('sendMessage')->once()->andReturnTrue();
        });
        $job = new DeliverAlertEventJob($event->id);
        $job->handle(app(TelegramNotifyService::class));
        $job->handle(app(TelegramNotifyService::class));

        $this->assertDatabaseHas('alert_deliveries', [
            'alert_event_id' => $event->id,
            'status' => 'sent',
            'attempts' => 1,
        ]);
    }

    public function test_database_rejects_a_second_delivery_for_the_same_event(): void
    {
        [$alert, $game] = $this->activeAlert(100);
        $event = $this->event($alert, $game);
        AlertDelivery::query()->create(['alert_event_id' => $event->id, 'status' => 'pending']);

        $this->expectException(QueryException::class);
        AlertDelivery::query()->create(['alert_event_id' => $event->id, 'status' => 'pending']);
    }

    public function test_database_rejects_a_second_event_in_the_same_alert_cycle(): void
    {
        [$alert, $game] = $this->activeAlert(100);
        $this->event($alert, $game);

        $this->expectException(QueryException::class);
        $this->event($alert, $game);
    }

    public function test_alert_history_api_is_limited_to_the_authenticated_user(): void
    {
        [$alert, $game] = $this->activeAlert(100);
        $event = AlertEvent::query()->create([
            'favorite_alert_id' => $alert->id,
            'alert_cycle' => 0,
            'user_id' => $alert->favorite->user_id,
            'favorite_id' => $alert->favorite_id,
            'game_id' => $game->id,
            'source' => 'steam',
            'offer_kind' => 'official',
            'offer_price_rub' => 99,
            'observed_at' => now(),
        ]);
        AlertDelivery::query()->create(['alert_event_id' => $event->id, 'status' => 'sent', 'attempts' => 1, 'sent_at' => now()]);

        $this->actingAs($alert->favorite->user, 'sanctum')
            ->getJson('/api/me/alerts/events')
            ->assertOk()
            ->assertJsonPath('items.0.id', $event->id)
            ->assertJsonPath('items.0.delivery.status', 'sent');
    }

    private function activeAlert(float $target, bool $linkedTelegram = false): array
    {
        $user = User::factory()->create(['telegram_chat_id' => $linkedTelegram ? '123456' : null]);
        $game = Game::query()->create([
            'steam_appid' => random_int(1000, 999999),
            'name' => 'Test game',
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
            'target_value' => $target,
            'status' => 'active',
        ]);

        return [$alert, $game];
    }

    private function price(Game $game, string $source, string $kind, float $amount, ?string $title = null, ?string $url = null): void
    {
        CurrentGamePrice::query()->create([
            'game_id' => $game->id,
            'source' => $source,
            'offer_kind' => $kind,
            'min_price_rub' => $amount,
            'avg_price_rub' => $amount,
            'offer_count' => 1,
            'cheapest_offer_title' => $title,
            'cheapest_offer_url' => $url,
            'observed_at' => now(),
        ]);
    }

    private function event(FavoriteAlert $alert, Game $game): AlertEvent
    {
        return AlertEvent::query()->create([
            'favorite_alert_id' => $alert->id,
            'alert_cycle' => 0,
            'user_id' => $alert->favorite->user_id,
            'favorite_id' => $alert->favorite_id,
            'game_id' => $game->id,
            'source' => 'steam',
            'offer_kind' => 'official',
            'offer_price_rub' => 99,
            'observed_at' => now(),
        ]);
    }
}
