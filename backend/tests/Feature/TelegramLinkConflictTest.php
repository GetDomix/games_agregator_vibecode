<?php

namespace Tests\Feature;

use App\Models\TelegramLinkCode;
use App\Models\User;
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
}
