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
use App\Models\GamePriceObservation;
use App\Models\User;
use App\Services\Alerts\AlertEvaluationService;
use App\Services\Alerts\FavoriteAlertSettingsService;
use App\Services\Telegram\TelegramNotifyService;
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
        $fresh = $this->price($game, 'plati', 'gift', 90, 'Gift offer', 'https://example.test/gift');

        $created = app(AlertEvaluationService::class)->evaluate($game, 'plati', [$fresh->id]);

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

    public function test_a_refresh_cannot_trigger_an_alert_from_a_different_stale_source(): void
    {
        Queue::fake();
        [$alert, $game] = $this->activeAlert(100, true);
        FavoriteAlertScope::query()->create(['favorite_alert_id' => $alert->id, 'source' => 'plati', 'offer_kind' => 'gift']);
        $fresh = $this->price($game, 'plati', 'gift', 90, 'Old gift offer', 'https://example.test/old-gift');

        $evaluator = app(AlertEvaluationService::class);
        $this->assertSame(0, $evaluator->evaluate($game, 'steam'));
        $this->assertDatabaseCount('alert_events', 0);
        $this->assertDatabaseHas('favorite_alerts', ['id' => $alert->id, 'status' => 'active']);

        $this->assertSame(1, $evaluator->evaluate($game, 'plati', [$fresh->id]));
        $this->assertDatabaseHas('alert_events', [
            'favorite_alert_id' => $alert->id,
            'source' => 'plati',
            'offer_kind' => 'gift',
            'offer_price_rub' => 90,
        ]);
    }

    public function test_duplicate_evaluation_creates_one_event_and_rearm_starts_a_new_cycle(): void
    {
        Queue::fake();
        [$alert, $game] = $this->activeAlert(100);
        FavoriteAlertScope::query()->create(['favorite_alert_id' => $alert->id, 'source' => 'steam', 'offer_kind' => 'official']);
        $fresh = $this->price($game, 'steam', 'official', 100);

        $evaluator = app(AlertEvaluationService::class);
        $this->assertSame(1, $evaluator->evaluate($game, 'steam', [$fresh->id]));
        $this->assertSame(0, $evaluator->evaluate($game, 'steam', [$fresh->id]));
        $this->assertDatabaseCount('alert_events', 1);

        app(FavoriteAlertSettingsService::class)->rearm($alert->refresh()->favorite);
        $this->assertSame(1, $evaluator->evaluate($game, 'steam', [$fresh->id]));
        $this->assertDatabaseCount('alert_events', 2);
        $this->assertDatabaseHas('alert_events', ['favorite_alert_id' => $alert->id, 'alert_cycle' => 1]);
    }

    public function test_event_is_rejected_when_settings_change_after_candidate_snapshot(): void
    {
        [$alert, $game] = $this->activeAlert(100);
        FavoriteAlertScope::query()->create(['favorite_alert_id' => $alert->id, 'source' => 'steam', 'offer_kind' => 'official']);
        $price = $this->price($game, 'steam', 'official', 90);
        $service = app(AlertEvaluationService::class);
        $expectation = (new \ReflectionMethod($service, 'expectation'))->invoke($service, $alert->fresh()->load('scopes'));

        // Mimics a settings request winning the race after evaluation selected 90 RUB.
        $alert->update(['condition_type' => 'discount_percent', 'target_value' => 30, 'cycle' => 1]);
        $event = (new \ReflectionMethod($service, 'createEvent'))->invoke($service, $alert->id, $price, $expectation);

        $this->assertNull($event);
        $this->assertDatabaseCount('alert_events', 0);
        $this->assertDatabaseHas('favorite_alerts', ['id' => $alert->id, 'status' => 'active', 'cycle' => 1]);
    }

    public function test_failed_delivery_keeps_the_same_event_and_can_be_retried(): void
    {
        Queue::fake();
        [$alert, $game] = $this->activeAlert(100, true);
        FavoriteAlertScope::query()->create(['favorite_alert_id' => $alert->id, 'source' => 'steam', 'offer_kind' => 'official']);
        $fresh = $this->price($game, 'steam', 'official', 99);
        app(AlertEvaluationService::class)->evaluate($game, 'steam', [$fresh->id]);
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
        $fresh = $this->price($game, 'steam', 'official', 99);
        app(AlertEvaluationService::class)->evaluate($game, 'steam', [$fresh->id]);
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

    public function test_discount_requires_at_least_threshold_on_refreshed_steam_official_price(): void
    {
        [$alert, $game] = $this->activeAlert(25);
        $alert->update(['condition_type' => 'discount_percent']);
        FavoriteAlertScope::query()->create(['favorite_alert_id' => $alert->id, 'source' => 'steam', 'offer_kind' => 'official']);
        $below = $this->price($game, 'steam', 'official', 100, discount: 24);
        $this->price($game, 'plati', 'gift', 50, discount: 100);
        $this->assertSame(0, app(AlertEvaluationService::class)->evaluate($game, 'steam'));
        $this->assertSame(0, app(AlertEvaluationService::class)->evaluate($game, 'plati'));
        $atThreshold = $this->price($game, 'steam', 'official', 100, discount: 25);
        $this->assertSame(0, app(AlertEvaluationService::class)->evaluate($game, 'steam', [$below->id]));
        $this->assertSame(1, app(AlertEvaluationService::class)->evaluate($game, 'steam', [$atThreshold->id]));
    }

    public function test_target_and_discount_use_only_the_supplied_immutable_fresh_snapshots(): void
    {
        [$target, $game] = $this->activeAlert(100);
        FavoriteAlertScope::query()->create(['favorite_alert_id' => $target->id, 'source' => 'steam', 'offer_kind' => 'official']);
        $freshTarget = $this->price($game, 'steam', 'official', 99, 'Snapshot title');
        CurrentGamePrice::query()->where('game_id', $game->id)->update(['min_price_rub' => 1, 'cheapest_offer_title' => 'Later mutable row']);

        $service = app(AlertEvaluationService::class);
        $this->assertSame(0, $service->evaluate($game, 'steam'));
        $this->assertSame(1, $service->evaluate($game, 'steam', [$freshTarget->id]));
        $this->assertDatabaseHas('alert_events', ['favorite_alert_id' => $target->id, 'offer_price_rub' => 99, 'offer_title' => 'Snapshot title']);

        [$discount, $discountGame] = $this->activeAlert(30);
        $discount->update(['condition_type' => 'discount_percent']);
        FavoriteAlertScope::query()->create(['favorite_alert_id' => $discount->id, 'source' => 'steam', 'offer_kind' => 'official']);
        $freshDiscount = $this->price($discountGame, 'steam', 'official', 250, discount: 30);
        $wrongGame = $this->observation(Game::query()->create(['steam_appid' => 12344321, 'name' => 'Wrong', 'release_status' => Game::RELEASE_STATUS_RELEASED]), 'steam', 'official', 1);
        CurrentGamePrice::query()->where('game_id', $discountGame->id)->update(['discount_percent' => 100, 'min_price_rub' => 1]);

        $this->assertSame(0, $service->evaluate($discountGame, 'plati', [$freshDiscount->id]));
        $this->assertSame(0, $service->evaluate($discountGame, 'steam', [$wrongGame->id]));
        $this->assertSame(1, $service->evaluate($discountGame, 'steam', [$freshDiscount->id]));
        $this->assertDatabaseHas('alert_events', ['favorite_alert_id' => $discount->id, 'offer_price_rub' => 250]);
    }

    public function test_new_low_uses_only_strictly_lower_fresh_observation_ids(): void
    {
        [$alert, $game] = $this->activeAlert(0);
        $alert->update(['condition_type' => 'new_low', 'target_value' => null]);
        FavoriteAlertScope::query()->create(['favorite_alert_id' => $alert->id, 'source' => 'steam', 'offer_kind' => 'official']);
        $baseline = $this->observation($game, 'steam', 'official', 100);
        $this->assertSame(0, app(AlertEvaluationService::class)->evaluate($game, 'steam', [$baseline->id]));
        $equal = $this->observation($game, 'steam', 'official', 100);
        $this->assertSame(0, app(AlertEvaluationService::class)->evaluate($game, 'steam', [$equal->id]));
        $lower = $this->observation($game, 'steam', 'official', 99);
        $other = $this->observation($game, 'plati', 'gift', 1);
        $otherGame = Game::query()->create(['steam_appid' => 991122, 'name' => 'Other game', 'release_status' => Game::RELEASE_STATUS_RELEASED]);
        $unrelated = $this->observation($otherGame, 'steam', 'official', 1);
        $this->assertSame(0, app(AlertEvaluationService::class)->evaluate($game, 'steam', [$baseline->id, $other->id, $unrelated->id]));
        $this->assertSame(1, app(AlertEvaluationService::class)->evaluate($game, 'steam', [$other->id, $lower->id]));
        $this->assertSame(0, app(AlertEvaluationService::class)->evaluate($game, 'steam', [$lower->id]));
        $this->assertDatabaseCount('alert_events', 1);
    }

    public function test_new_low_compares_against_all_observed_history_not_only_the_previous_point(): void
    {
        [$alert, $game] = $this->activeAlert(1);
        $alert->update(['condition_type' => 'new_low', 'target_value' => null]);
        FavoriteAlertScope::query()->create(['favorite_alert_id' => $alert->id, 'source' => 'steam', 'offer_kind' => 'official']);
        $baseline = $this->observation($game, 'steam', 'official', 100);
        $this->assertSame(0, app(AlertEvaluationService::class)->evaluate($game, 'steam', [$baseline->id]));
        $low = $this->observation($game, 'steam', 'official', 80);
        $this->assertSame(1, app(AlertEvaluationService::class)->evaluate($game, 'steam', [$low->id]));
        app(FavoriteAlertSettingsService::class)->rearm($alert->fresh()->favorite);
        $ninety = $this->observation($game, 'steam', 'official', 90);
        $eightyFive = $this->observation($game, 'steam', 'official', 85);
        $this->assertSame(0, app(AlertEvaluationService::class)->evaluate($game, 'steam', [$ninety->id]));
        $this->assertSame(0, app(AlertEvaluationService::class)->evaluate($game, 'steam', [$eightyFive->id]));
        $this->assertDatabaseCount('alert_events', 1);
    }

    public function test_active_alert_with_an_occupied_legacy_cycle_is_repaired_before_creating_fresh_event(): void
    {
        [$alert, $game] = $this->activeAlert(100);
        FavoriteAlertScope::query()->create(['favorite_alert_id' => $alert->id, 'source' => 'steam', 'offer_kind' => 'official']);
        $this->event($alert, $game);
        $fresh = $this->observation($game, 'steam', 'official', 99);

        $this->assertSame(1, app(AlertEvaluationService::class)->evaluate($game, 'steam', [$fresh->id]));
        $this->assertDatabaseHas('favorite_alerts', ['id' => $alert->id, 'status' => 'triggered', 'cycle' => 1]);
        $this->assertDatabaseHas('alert_events', ['favorite_alert_id' => $alert->id, 'alert_cycle' => 1, 'offer_price_rub' => 99]);
        $this->assertDatabaseCount('alert_events', 2);
    }

    public function test_disabled_telegram_radar_keeps_event_history_but_skips_delivery_and_queue(): void
    {
        Queue::fake();
        [$alert, $game] = $this->activeAlert(100, true);
        $alert->favorite->user->update(['radar_enabled' => false]);
        FavoriteAlertScope::query()->create(['favorite_alert_id' => $alert->id, 'source' => 'steam', 'offer_kind' => 'official']);
        $fresh = $this->price($game, 'steam', 'official', 99);

        $this->assertSame(1, app(AlertEvaluationService::class)->evaluate($game, 'steam', [$fresh->id]));
        $event = AlertEvent::query()->where('favorite_alert_id', $alert->id)->firstOrFail();
        $this->assertDatabaseHas('alert_deliveries', ['alert_event_id' => $event->id, 'status' => 'skipped', 'last_error' => 'Telegram radar is disabled']);
        Queue::assertNothingPushed();
    }

    public function test_delivery_rechecks_radar_toggle_after_pending_job_was_queued(): void
    {
        Queue::fake();
        [$alert, $game] = $this->activeAlert(100, true);
        FavoriteAlertScope::query()->create(['favorite_alert_id' => $alert->id, 'source' => 'steam', 'offer_kind' => 'official']);
        $fresh = $this->price($game, 'steam', 'official', 99);
        app(AlertEvaluationService::class)->evaluate($game, 'steam', [$fresh->id]);
        $event = AlertEvent::query()->where('favorite_alert_id', $alert->id)->firstOrFail();
        $event->user->update(['radar_enabled' => false]);

        $this->mock(TelegramNotifyService::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('sendMessage');
        });
        (new DeliverAlertEventJob($event->id))->handle(app(TelegramNotifyService::class));
        $this->assertDatabaseHas('alert_deliveries', ['alert_event_id' => $event->id, 'status' => 'skipped', 'last_error' => 'Telegram radar is disabled']);
    }

    public function test_delivery_uses_condition_aware_same_cycle_headlines(): void
    {
        [$target, $targetGame] = $this->activeAlert(500, true);
        $targetEvent = $this->pendingEvent($target, $targetGame);

        [$discount, $discountGame] = $this->activeAlert(30, true);
        $discount->update(['condition_type' => 'discount_percent']);
        $discountEvent = $this->pendingEvent($discount, $discountGame);

        [$newLow, $newLowGame] = $this->activeAlert(1, true);
        $newLow->update(['condition_type' => 'new_low', 'target_value' => null]);
        $newLowEvent = $this->pendingEvent($newLow, $newLowGame);

        $messages = [];
        $this->mock(TelegramNotifyService::class, function (MockInterface $mock) use (&$messages): void {
            $mock->shouldReceive('sendMessage')->times(3)->andReturnUsing(function (string $chatId, string $message) use (&$messages): bool {
                $messages[] = $message;

                return true;
            });
        });
        $telegram = app(TelegramNotifyService::class);
        (new DeliverAlertEventJob($targetEvent->id))->handle($telegram);
        (new DeliverAlertEventJob($discountEvent->id))->handle($telegram);
        (new DeliverAlertEventJob($newLowEvent->id))->handle($telegram);

        $this->assertStringContainsString('Цель цены достигнута', $messages[0]);
        $this->assertStringContainsString('Скидка Steam достигла 30%', $messages[1]);
        $this->assertStringNotContainsString('30 ₽', $messages[1]);
        $this->assertStringContainsString('Новый минимум с начала наблюдений', $messages[2]);
    }

    public function test_delivery_uses_generic_headline_when_alert_was_rearmed_or_edited(): void
    {
        [$alert, $game] = $this->activeAlert(500, true);
        $event = $this->pendingEvent($alert, $game);
        $alert->update(['condition_type' => 'new_low', 'target_value' => null, 'cycle' => 1]);

        $message = '';
        $this->mock(TelegramNotifyService::class, function (MockInterface $mock) use (&$message): void {
            $mock->shouldReceive('sendMessage')->once()->andReturnUsing(function (string $chatId, string $body) use (&$message): bool {
                $message = $body;

                return true;
            });
        });
        (new DeliverAlertEventJob($event->id))->handle(app(TelegramNotifyService::class));

        $this->assertStringContainsString('Ценовой сигнал сработал', $message);
        $this->assertStringNotContainsString('Новый минимум с начала наблюдений', $message);
    }

    private function activeAlert(float $target, bool $linkedTelegram = false): array
    {
        $user = User::factory()->create(['telegram_chat_id' => $linkedTelegram ? (string) random_int(100000, 999999) : null]);
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

    private function price(Game $game, string $source, string $kind, float $amount, ?string $title = null, ?string $url = null, ?int $discount = null): GamePriceObservation
    {
        CurrentGamePrice::query()->updateOrCreate([
            'game_id' => $game->id, 'source' => $source, 'offer_kind' => $kind,
        ], [
            'min_price_rub' => $amount,
            'avg_price_rub' => $amount,
            'offer_count' => 1,
            'cheapest_offer_title' => $title,
            'cheapest_offer_url' => $url,
            'observed_at' => now(),
            'discount_percent' => $discount,
        ]);
        return GamePriceObservation::query()->create([
            'game_id' => $game->id,
            'source' => $source,
            'offer_kind' => $kind,
            'min_price_rub' => $amount,
            'discount_percent' => $discount,
            'offer_title' => $title,
            'offer_url' => $url,
            'observed_at' => now(),
        ]);
    }

    private function observation(Game $game, string $source, string $kind, float $amount): GamePriceObservation
    {
        return GamePriceObservation::query()->create(['game_id' => $game->id, 'source' => $source, 'offer_kind' => $kind, 'min_price_rub' => $amount, 'observed_at' => now()]);
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

    private function pendingEvent(FavoriteAlert $alert, Game $game): AlertEvent
    {
        $event = $this->event($alert, $game);
        AlertDelivery::query()->create(['alert_event_id' => $event->id, 'status' => AlertDelivery::STATUS_PENDING]);

        return $event;
    }
}
