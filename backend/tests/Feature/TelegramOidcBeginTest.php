<?php

namespace Tests\Feature;

use App\Models\OidcTransaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TelegramOidcBeginTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_begin_creates_short_lived_pkce_transaction(): void
    {
        config()->set('gpa.telegram_oidc_client_id', '123456');
        config()->set('gpa.telegram_oidc_client_secret', 'test-secret');
        config()->set('gpa.telegram_oidc_redirect_uri', 'https://igroskan.test/api/auth/telegram/callback');
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/telegram/oidc/begin');

        $response->assertOk();
        $url = $response->json('authorization_url');
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        $this->assertSame('123456', $query['client_id']);
        $this->assertSame('S256', $query['code_challenge_method']);
        $this->assertSame('openid profile telegram:bot_access', $query['scope']);
        $this->assertDatabaseHas('oidc_transactions', [
            'provider' => 'telegram',
            'state' => $query['state'],
            'user_id' => $user->id,
        ]);

        $transaction = OidcTransaction::query()->where('state', $query['state'])->firstOrFail();
        $this->assertTrue($transaction->expires_at->between(now()->addMinutes(9), now()->addMinutes(11)));
        $this->assertSame(
            rtrim(strtr(base64_encode(hash('sha256', $transaction->code_verifier, true)), '+/', '-_'), '='),
            $query['code_challenge']
        );
    }

    public function test_email_less_telegram_account_can_be_serialized_safely(): void
    {
        $telegramUser = User::factory()->create(['email' => null]);

        $public = $telegramUser->toPublicArray();

        $this->assertNull($public['email']);
        $this->assertFalse($public['can_access_admin']);
    }
}
