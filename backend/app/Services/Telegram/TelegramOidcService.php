<?php

namespace App\Services\Telegram;

use App\Models\ExternalIdentity;
use App\Models\OidcTransaction;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Jose\Component\Core\AlgorithmManager;
use Jose\Component\Core\JWKSet;
use Jose\Component\Signature\Algorithm\RS256;
use Jose\Component\Signature\JWSVerifier;
use Jose\Component\Signature\Serializer\CompactSerializer;

class TelegramOidcService
{
    public function __construct(private readonly TelegramIdentityLock $identityLock)
    {
    }

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

        return DB::transaction(function () use ($transaction, $claims, $merge): array {
            $subject = (string) $claims['sub'];
            // Must precede user locks: all Telegram identity creators use this
            // key, then lock user rows, then the external identity row.
            $this->identityLock->acquire($subject);
            $lockedTransaction = OidcTransaction::query()
                ->whereKey($transaction->id)
                ->where('provider', 'telegram')
                ->whereNull('used_at')
                ->where('expires_at', '>', now())
                ->lockForUpdate()
                ->first();
            if (! $lockedTransaction) {
                throw new \RuntimeException('Этот вход Telegram уже был использован');
            }

            $identityMeta = ExternalIdentity::query()
                ->where('provider', 'telegram')
                ->where('provider_subject', $subject)
                ->first(['id', 'user_id']);
            $userIds = array_filter([$lockedTransaction->user_id, $identityMeta?->user_id]);
            $lockedUsers = User::query()->whereIn('id', $userIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $websiteUser = $lockedTransaction->user_id ? $lockedUsers->get($lockedTransaction->user_id) : null;
            $telegramUser = $identityMeta ? $lockedUsers->get($identityMeta->user_id) : null;
            $identity = ExternalIdentity::query()
                ->where('provider', 'telegram')
                ->where('provider_subject', $subject)
                ->lockForUpdate()
                ->first();
            if ($identity && (! $identityMeta || $identity->user_id !== $identityMeta->user_id || ! $telegramUser)) {
                throw new \RuntimeException('Telegram identity changed during resolution');
            }

            $created = ! $identity;
            if (! $identity) {
                $telegramUser = User::query()->create([
                    'name' => $claims['name'] ?? 'Telegram user',
                    'display_name' => $claims['name'] ?? null,
                    'email' => null,
                    'password' => Str::random(64),
                ]);
                $identity = ExternalIdentity::query()->create([
                    'provider' => 'telegram',
                    'provider_subject' => $subject,
                    'user_id' => $telegramUser->id,
                    'profile' => ['username' => $claims['preferred_username'] ?? null],
                ]);
            }

            $user = $websiteUser ?? $telegramUser;
            $report = ['favorites_moved' => 0];
            if ($websiteUser && $telegramUser->id !== $websiteUser->id) {
                $report = $merge->merge($websiteUser, $telegramUser);
                $user = $websiteUser;
            }

            // The merge may have reassigned this row to the website user. Do
            // not write a stale owner back; only refresh profile metadata.
            $identity->update(['profile' => ['username' => $claims['preferred_username'] ?? null]]);
            $lockedTransaction->forceFill(['used_at' => now()])->save();

            return [
                'user' => $user->fresh(),
                'created' => $created,
                'merged' => $websiteUser !== null && ! $created,
                'report' => $report,
            ];
        });
    }
}
