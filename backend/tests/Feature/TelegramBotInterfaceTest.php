<?php

namespace Tests\Feature;

use App\Jobs\RefreshGameSourceJob;
use App\Models\CurrentGamePrice;
use App\Models\ExternalIdentity;
use App\Models\Favorite;
use App\Models\Game;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TelegramBotInterfaceTest extends TestCase
{
    use RefreshDatabase;

    private array $headers = ['X-Radar-Token' => 'test-service-token'];

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('gpa.radar_service_token', 'test-service-token');
    }

    public function test_bot_session_creates_shared_identity_and_favorite(): void
    {
        Queue::fake();
        $this->postJson('/api/internal/telegram/session', [
            'telegram_user_id' => '101', 'chat_id' => '101', 'username' => 'igroscan', 'display_name' => 'Игрок',
        ], $this->headers)->assertOk()->assertJsonPath('user.telegram_linked', true);

        $user = ExternalIdentity::query()->where('provider_subject', '101')->firstOrFail()->user;
        $this->putJson('/api/internal/telegram/favorites', [
            'telegram_user_id' => '101', 'appid' => 777, 'game_name' => 'Shared Game', 'target_price_rub' => 799,
            'alert' => ['target_value' => 799, 'scopes' => [
                ['source' => 'steam', 'offer_kind' => 'official'], ['source' => 'ggsel', 'offer_kind' => 'gift'],
            ]],
        ], $this->headers)->assertCreated()->assertJsonPath('alert.scopes.1.source', 'ggsel');

        Sanctum::actingAs($user);
        $this->getJson('/api/me/favorites')->assertOk()
            ->assertJsonPath('items.0.game_name', 'Shared Game')
            ->assertJsonPath('items.0.alert.target_value', 799);
    }

    public function test_bot_card_uses_stored_prices_and_hides_marketplaces_for_announced_game(): void
    {
        $user = $this->botUser();
        $game = Game::query()->create(['steam_appid' => 778, 'name' => 'Soon', 'release_status' => 'announced']);
        CurrentGamePrice::query()->create(['game_id' => $game->id, 'source' => 'plati', 'offer_kind' => 'key', 'min_price_rub' => 10, 'observed_at' => now()]);

        $this->getJson('/api/internal/telegram/games/778?telegram_user_id=101', $this->headers)->assertOk()
            ->assertJsonPath('card.steam.name', 'Soon')->assertJsonCount(0, 'card.plati.by_kind')->assertJsonCount(0, 'card.ggsel.by_kind');

        Queue::fake();
        $this->getJson('/api/internal/telegram/games/779?telegram_user_id=101&q=Unknown', $this->headers)->assertOk()
            ->assertJsonPath('card.refreshing', true);
        Queue::assertPushed(RefreshGameSourceJob::class);
    }

    public function test_bot_alert_can_be_rearmed_and_service_token_is_required(): void
    {
        $this->getJson('/api/internal/telegram/favorites?telegram_user_id=101')->assertUnauthorized();
        $user = $this->botUser();
        $favorite = Favorite::query()->create(['user_id' => $user->id, 'appid' => 780, 'game_name' => 'Alert Game']);
        $favorite->alert()->create(['status' => 'triggered', 'target_value' => 500, 'cycle' => 1, 'triggered_at' => now()]);

        $this->postJson('/api/internal/telegram/favorites/780/alert/rearm', ['telegram_user_id' => '101'], $this->headers)
            ->assertOk()->assertJsonPath('alert.status', 'active');
    }

    public function test_invalid_bot_alert_does_not_create_a_favorite_or_refresh_jobs(): void
    {
        Queue::fake();
        $user = $this->botUser();

        $this->putJson('/api/internal/telegram/favorites', [
            'telegram_user_id' => '101',
            'appid' => 781,
            'game_name' => 'Invalid scope',
            'alert' => ['scopes' => [['source' => 'plati', 'offer_kind' => 'other']]],
        ], $this->headers)->assertUnprocessable();

        $this->assertDatabaseMissing('favorites', ['user_id' => $user->id, 'appid' => 781]);
        Queue::assertNothingPushed();
    }

    public function test_bot_can_save_a_plain_favorite_without_creating_an_alert(): void
    {
        Queue::fake();
        $user = $this->botUser();

        $this->putJson('/api/internal/telegram/favorites', [
            'telegram_user_id' => '101', 'appid' => 782, 'game_name' => 'Plain bot favorite',
        ], $this->headers)->assertCreated()->assertJsonPath('alert', null);

        $favorite = Favorite::query()->where('user_id', $user->id)->where('appid', 782)->firstOrFail();
        $this->assertDatabaseMissing('favorite_alerts', ['favorite_id' => $favorite->id]);
    }

    public function test_plain_bot_payload_leaves_an_existing_price_signal_unchanged(): void
    {
        $user = $this->botUser();
        $favorite = Favorite::query()->create(['user_id' => $user->id, 'appid' => 783, 'game_name' => 'Existing signal']);
        $alert = $favorite->alert()->create(['condition_type' => 'target_price', 'target_value' => 777, 'status' => 'triggered', 'cycle' => 4, 'triggered_at' => now()]);

        $this->putJson('/api/internal/telegram/favorites', [
            'telegram_user_id' => '101', 'appid' => 783, 'game_name' => 'Existing signal',
        ], $this->headers)->assertOk();

        $this->assertDatabaseHas('favorite_alerts', [
            'id' => $alert->id, 'condition_type' => 'target_price', 'target_value' => 777, 'status' => 'triggered', 'cycle' => 4,
        ]);
    }

    private function botUser(): User
    {
        $user = User::factory()->create(['telegram_chat_id' => '101']);
        ExternalIdentity::query()->create(['user_id' => $user->id, 'provider' => 'telegram', 'provider_subject' => '101']);

        return $user;
    }
}
