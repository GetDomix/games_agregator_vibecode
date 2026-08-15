<?php

namespace Tests\Feature;

use App\Models\OidcTransaction;
use App\Models\User;
use App\Services\Telegram\TelegramAccountMergeService;
use App\Services\Telegram\TelegramOidcService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
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

    public function test_failed_oidc_callback_does_not_consume_its_state_before_identity_locking_work(): void
    {
        config()->set('gpa.telegram_oidc_client_id', '123456');
        config()->set('gpa.telegram_oidc_client_secret', 'test-secret');
        config()->set('gpa.telegram_oidc_redirect_uri', 'https://igroskan.test/api/auth/telegram/callback');
        $transaction = OidcTransaction::query()->create([
            'provider' => 'telegram',
            'state' => 'unconsumed-oidc-state',
            'nonce' => 'nonce',
            'code_verifier' => 'verifier',
            'expires_at' => now()->addMinutes(10),
        ]);
        Http::fake([
            'https://oauth.telegram.org/token' => Http::response([], 400),
        ]);

        try {
            app(TelegramOidcService::class)->callback(
                'unconsumed-oidc-state',
                'bad-code',
                app(TelegramAccountMergeService::class),
            );
            $this->fail('OIDC callback must reject a token response without id_token.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Telegram не выдал токен входа', $exception->getMessage());
        }

        $this->assertNull($transaction->fresh()->used_at);
    }

    public function test_signed_oidc_callback_commits_its_state_and_canonical_identity_together(): void
    {
        config()->set('gpa.telegram_oidc_client_id', '123456');
        config()->set('gpa.telegram_oidc_client_secret', 'test-secret');
        config()->set('gpa.telegram_oidc_redirect_uri', 'https://igroskan.test/api/auth/telegram/callback');
        $first = $this->oidcState('first-subject-state');
        [$token, $jwks] = $this->signedTelegramToken('123456', $first->nonce, '777');
        Http::fake([
            'https://oauth.telegram.org/token' => Http::response(['id_token' => $token]),
            'https://oauth.telegram.org/.well-known/jwks.json' => Http::response($jwks),
        ]);

        $service = app(TelegramOidcService::class);
        $firstResult = $service->callback($first->state, 'first-code', app(TelegramAccountMergeService::class));

        $this->assertTrue($firstResult['created']);
        $this->assertSame(1, \App\Models\ExternalIdentity::query()->where('provider', 'telegram')->where('provider_subject', '777')->count());
        $this->assertNotNull($first->fresh()->used_at);
    }

    private function oidcState(string $state): OidcTransaction
    {
        return OidcTransaction::query()->create([
            'provider' => 'telegram',
            'state' => $state,
            'nonce' => 'nonce-'.$state,
            'code_verifier' => 'verifier-'.$state,
            'expires_at' => now()->addMinutes(10),
        ]);
    }

    /** @return array{string, array{keys: array<int, array<string, string>}} */
    private function signedTelegramToken(string $client, string $nonce, string $subject): array
    {
        $private = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $this->assertNotFalse($private);
        $details = openssl_pkey_get_details($private);
        $this->assertIsArray($details);
        $header = $this->base64Url(json_encode(['alg' => 'RS256', 'kid' => 'test-key'], JSON_THROW_ON_ERROR));
        $payload = $this->base64Url(json_encode([
            'iss' => 'https://oauth.telegram.org',
            'aud' => [$client],
            'exp' => time() + 300,
            'nonce' => $nonce,
            'sub' => $subject,
            'name' => 'Telegram player',
            'preferred_username' => 'player',
        ], JSON_THROW_ON_ERROR));
        $signed = $header.'.'.$payload;
        $this->assertTrue(openssl_sign($signed, $signature, $private, OPENSSL_ALGO_SHA256));

        return [
            $signed.'.'.$this->base64Url($signature),
            ['keys' => [[
                'kty' => 'RSA',
                'kid' => 'test-key',
                'alg' => 'RS256',
                'use' => 'sig',
                'n' => $this->base64Url($details['rsa']['n']),
                'e' => $this->base64Url($details['rsa']['e']),
            ]]],
        ];
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
