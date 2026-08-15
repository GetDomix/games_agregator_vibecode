<?php

namespace Tests\Feature;

use App\Models\TelegramLinkCode;
use App\Models\User;
use App\Models\ExternalIdentity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TelegramLinkConflictTest extends TestCase
{
    use RefreshDatabase;

    public function test_bot_link_does_not_silently_move_an_existing_chat_to_another_user(): void
    {
        config()->set('gpa.radar_service_token', 'test-service-token');
        User::factory()->create(['telegram_chat_id' => '777']);
        $target = User::factory()->create();
        TelegramLinkCode::query()->create([
            'user_id' => $target->id,
            'code' => 'SAFE-LINK',
            'expires_at' => now()->addMinutes(20),
        ]);

        $this->postJson('/api/internal/telegram/bind', [
            'code' => 'SAFE-LINK',
            'telegram_user_id' => '777',
            'chat_id' => '777',
        ], ['X-Radar-Token' => 'test-service-token'])
            ->assertConflict()
            ->assertJsonPath('detail', 'Этот Telegram уже привязан к другому аккаунту');

        $this->assertDatabaseHas('users', ['id' => $target->id, 'telegram_chat_id' => null]);
        $this->assertDatabaseHas('telegram_link_codes', ['code' => 'SAFE-LINK', 'used_at' => null]);
    }

    public function test_link_code_is_consumed_once_inside_the_subject_locked_transaction(): void
    {
        config()->set('gpa.radar_service_token', 'test-service-token');
        $target = User::factory()->create();
        TelegramLinkCode::query()->create([
            'user_id' => $target->id,
            'code' => 'ONCE-ONLY',
            'expires_at' => now()->addMinutes(20),
        ]);

        $payload = [
            'code' => 'ONCE-ONLY',
            'telegram_user_id' => '778',
            'chat_id' => '778',
            'telegram_username' => 'first',
        ];
        $headers = ['X-Radar-Token' => 'test-service-token'];

        $this->postJson('/api/internal/telegram/bind', $payload, $headers)->assertOk();
        $this->postJson('/api/internal/telegram/bind', [
            ...$payload,
            'telegram_username' => 'second-attempt',
        ], $headers)->assertNotFound();

        $this->assertNotNull(TelegramLinkCode::query()->where('code', 'ONCE-ONLY')->value('used_at'));
        $this->assertDatabaseHas('users', ['id' => $target->id, 'telegram_chat_id' => '778', 'telegram_username' => 'first']);
        $this->assertSame(1, ExternalIdentity::query()->where('provider', 'telegram')->where('provider_subject', '778')->count());
    }
}
