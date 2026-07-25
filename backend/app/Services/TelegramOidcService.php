<?php

namespace App\Services;

use App\Models\ExternalIdentity;
use App\Models\OidcTransaction;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Jose\Component\Core\AlgorithmManager;
use Jose\Component\Core\JWKSet;
use Jose\Component\Signature\Algorithm\RS256;
use Jose\Component\Signature\JWSVerifier;
use Jose\Component\Signature\Serializer\CompactSerializer;

class TelegramOidcService
{
    public function isConfigured(): bool
    {
        return (string) config('gpa.telegram_oidc_client_id') !== ''
            && (string) config('gpa.telegram_oidc_client_secret') !== ''
            && (string) config('gpa.telegram_oidc_redirect_uri') !== '';
    }

    public function begin(?int $userId): string
    {
        $client = (string) config('gpa.telegram_oidc_client_id');
        $redirect = (string) config('gpa.telegram_oidc_redirect_uri');

        if (! $this->isConfigured()) {
            throw new \RuntimeException('Telegram Login ещё не настроен');
        }

        $state = Str::random(64);
        $nonce = Str::random(64);
        $verifier = Str::random(96);
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

        OidcTransaction::query()->create([
            'provider' => 'telegram',
            'state' => $state,
            'nonce' => $nonce,
            'code_verifier' => $verifier,
            'user_id' => $userId,
            'expires_at' => now()->addMinutes(10),
        ]);

        return 'https://oauth.telegram.org/auth?'.http_build_query([
            'client_id' => $client,
            'redirect_uri' => $redirect,
            'response_type' => 'code',
            'scope' => 'openid profile telegram:bot_access',
            'state' => $state,
            'nonce' => $nonce,
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
        ]);
    }

    public function callback(string $state, string $code, TelegramAccountMergeService $merge): array
    {
        $transaction = OidcTransaction::query()
            ->where('provider', 'telegram')
            ->where('state', $state)
            ->whereNull('used_at')
            ->where('expires_at', '>', now())
            ->firstOrFail();

        $client = (string) config('gpa.telegram_oidc_client_id');
        $secret = (string) config('gpa.telegram_oidc_client_secret');
        $redirect = (string) config('gpa.telegram_oidc_redirect_uri');

        if (! $this->isConfigured()) {
            throw new \RuntimeException('Telegram Login ещё не настроен');
        }

        $token = Http::asForm()->withBasicAuth($client, $secret)
            ->timeout((float) config('gpa.http_timeout', 20))
            ->post('https://oauth.telegram.org/token', [
                'grant_type' => 'authorization_code',
                'code' => $code,
                'redirect_uri' => $redirect,
                'client_id' => $client,
                'code_verifier' => $transaction->code_verifier,
            ]);

        if (! $token->successful() || ! $token->json('id_token')) {
            throw new \RuntimeException('Telegram не выдал токен входа');
        }

        $jws = (new CompactSerializer)->unserialize((string) $token->json('id_token'));
        $keys = Http::timeout((float) config('gpa.http_timeout', 20))
            ->get('https://oauth.telegram.org/.well-known/jwks.json');

        if (! $keys->successful()) {
            throw new \RuntimeException('Не удалось получить ключи Telegram');
        }

        $verified = (new JWSVerifier(new AlgorithmManager([new RS256])))
            ->verifyWithKeySet($jws, JWKSet::createFromJson($keys->body()), 0);

        if (! $verified) {
            throw new \RuntimeException('Подпись Telegram token недействительна');
        }

        $claims = json_decode((string) $jws->getPayload(), true, 512, JSON_THROW_ON_ERROR);
        $audience = (array) ($claims['aud'] ?? []);

        if (
            ($claims['iss'] ?? null) !== 'https://oauth.telegram.org'
            || ! in_array($client, $audience, true)
            || (int) ($claims['exp'] ?? 0) < time()
            || ! hash_equals($transaction->nonce, (string) ($claims['nonce'] ?? ''))
            || empty($claims['sub'])
        ) {
            throw new \RuntimeException('Claims Telegram token недействительны');
        }

        // Claim the state only after signature and claims are valid. A competing
        // callback loses this conditional update and cannot merge twice.
        $claimed = OidcTransaction::query()
            ->whereKey($transaction->id)
            ->whereNull('used_at')
            ->update(['used_at' => now()]);

        if ($claimed !== 1) {
            throw new \RuntimeException('Этот вход Telegram уже был использован');
        }

        $identity = ExternalIdentity::query()
            ->where('provider', 'telegram')
            ->where('provider_subject', (string) $claims['sub'])
            ->first();

        $telegramUser = $identity?->user ?? User::query()->create([
            'name' => $claims['name'] ?? 'Telegram user',
            'display_name' => $claims['name'] ?? null,
            'email' => null,
            'password' => Str::random(64),
        ]);
        $user = $transaction->user ?? $telegramUser;
        $report = ['favorites_moved' => 0];

        if ($transaction->user && $telegramUser->id !== $transaction->user->id) {
            $report = $merge->merge($transaction->user, $telegramUser);
        }

        ExternalIdentity::query()->updateOrCreate(
            ['provider' => 'telegram', 'provider_subject' => (string) $claims['sub']],
            ['user_id' => $user->id, 'profile' => ['username' => $claims['preferred_username'] ?? null]]
        );

        return [
            'user' => $user,
            'created' => ! $identity,
            'merged' => $transaction->user !== null && $identity !== null,
            'report' => $report,
        ];
    }
}
