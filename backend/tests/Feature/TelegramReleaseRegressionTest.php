<?php

namespace Tests\Feature;

use App\Models\ExternalIdentity;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TelegramReleaseRegressionTest extends TestCase
{
    use RefreshDatabase;

    private array $headers = ['X-Radar-Token' => 'test-service-token'];

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('gpa.radar_service_token', 'test-service-token');
    }

    public function test_group_chat_cannot_create_or_join_an_account(): void
    {
        $this->postJson('/api/internal/telegram/session', [
            'telegram_user_id' => '202',
            'chat_id' => '-100123',
            'display_name' => 'Second user',
        ], $this->headers)->assertUnprocessable();

        $this->assertDatabaseMissing('external_identities', ['provider_subject' => '202']);
    }

    public function test_unlinking_telegram_revokes_bot_access_until_the_user_links_again(): void
    {
        $this->postJson('/api/internal/telegram/session', [
            'telegram_user_id' => '101',
            'chat_id' => '101',
            'display_name' => 'Player',
        ], $this->headers)->assertOk();
        $user = ExternalIdentity::query()->where('provider_subject', '101')->firstOrFail()->user;

        $this->actingAs($user, 'sanctum')
            ->deleteJson('/api/telegram/link')
            ->assertOk()
            ->assertJsonPath('linked', false);

        $this->getJson('/api/internal/telegram/favorites?telegram_user_id=101', $this->headers)
            ->assertUnauthorized();
    }

}
