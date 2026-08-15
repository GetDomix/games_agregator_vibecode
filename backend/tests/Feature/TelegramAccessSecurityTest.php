<?php

namespace Tests\Feature;

use App\Models\ExternalIdentity;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TelegramAccessSecurityTest extends TestCase
{
    use RefreshDatabase;

    private array $headers = ['X-Radar-Token' => 'test-service-token'];

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('gpa.radar_service_token', 'test-service-token');
    }

    public function test_repeated_private_start_reuses_the_same_identity_and_account(): void
    {
        $payload = ['telegram_user_id' => '101', 'chat_id' => '101', 'display_name' => 'Player'];
        $this->postJson('/api/internal/telegram/session', $payload, $this->headers)->assertOk();
        $this->postJson('/api/internal/telegram/session', $payload, $this->headers)->assertOk();

        $this->assertSame(1, ExternalIdentity::query()->where('provider_subject', '101')->count());
        $this->assertSame(1, User::query()->where('telegram_chat_id', '101')->count());
    }

    public function test_existing_bot_session_preserves_the_users_radar_toggle(): void
    {
        $disabled = User::factory()->create([
            'telegram_chat_id' => '102',
            'radar_enabled' => false,
        ]);
        ExternalIdentity::query()->create([
            'user_id' => $disabled->id,
            'provider' => 'telegram',
            'provider_subject' => '102',
        ]);
        $enabled = User::factory()->create([
            'telegram_chat_id' => '103',
            'radar_enabled' => true,
        ]);
        ExternalIdentity::query()->create([
            'user_id' => $enabled->id,
            'provider' => 'telegram',
            'provider_subject' => '103',
        ]);

        foreach (['102', '103'] as $telegramUserId) {
            $this->postJson('/api/internal/telegram/session', [
                'telegram_user_id' => $telegramUserId,
                'chat_id' => $telegramUserId,
            ], $this->headers)->assertOk();
        }

        $this->assertFalse((bool) $disabled->fresh()->radar_enabled);
        $this->assertTrue((bool) $enabled->fresh()->radar_enabled);
    }

    public function test_oidc_not_ready_is_a_safe_service_unavailable_response(): void
    {
        config()->set('gpa.telegram_oidc_client_id', '');
        config()->set('gpa.telegram_oidc_client_secret', '');
        config()->set('gpa.telegram_oidc_redirect_uri', '');

        $this->postJson('/api/auth/telegram/begin')
            ->assertStatus(503)
            ->assertJsonPath('detail', 'Telegram Login временно не настроен. Попробуй позже.');
    }

    public function test_hardening_migration_clears_only_unsafe_group_chat_delivery(): void
    {
        $group = User::factory()->create(['telegram_chat_id' => '-100123', 'telegram_username' => 'group', 'radar_enabled' => true]);
        $private = User::factory()->create(['telegram_chat_id' => '101', 'telegram_username' => 'player', 'radar_enabled' => true]);

        (require database_path('migrations/2026_07_25_190000_harden_telegram_personal_chat_links.php'))->up();

        $this->assertDatabaseHas('users', ['id' => $group->id, 'telegram_chat_id' => null, 'radar_enabled' => false]);
        $this->assertDatabaseHas('users', ['id' => $private->id, 'telegram_chat_id' => '101', 'radar_enabled' => true]);
    }
}
