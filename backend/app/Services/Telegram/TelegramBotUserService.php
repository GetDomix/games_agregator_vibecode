<?php

namespace App\Services\Telegram;

use App\Models\ExternalIdentity;
use App\Models\User;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TelegramBotUserService
{
    public function __construct(private readonly TelegramIdentityLock $identityLock)
    {
    }

    public function resolve(string $telegramUserId, string $chatId, ?string $username, ?string $displayName): User
    {
        return DB::transaction(function () use ($telegramUserId, $chatId, $username, $displayName): User {
            if ($telegramUserId !== $chatId) {
                throw new HttpResponseException(response()->json([
                    'detail' => 'Аккаунт Игроскана доступен только в личном чате с ботом.',
                ], 422));
            }

            // Serializes identity creation. All row locks below follow the
            // global Telegram order: user -> external identity.
            $this->identityLock->acquire($telegramUserId);
            $identityMeta = ExternalIdentity::query()
                ->where('provider', 'telegram')
                ->where('provider_subject', $telegramUserId)
                ->first(['id', 'user_id']);
            $user = $identityMeta
                ? User::query()->lockForUpdate()->find($identityMeta->user_id)
                : null;
            $identity = ExternalIdentity::query()
                ->where('provider', 'telegram')
                ->where('provider_subject', $telegramUserId)
                ->lockForUpdate()
                ->first();
            if ($identity && $identityMeta && $identity->user_id !== $identityMeta->user_id) {
                // A merge changed the relation while this request waited for
                // the user row; retrying the request sees the fresh owner.
                throw new \RuntimeException('Telegram identity changed during resolution');
            }
            if (! $identity) {
                $name = trim((string) $displayName) ?: 'Telegram user';
                $user = User::query()->create([
                    'name' => mb_substr($name, 0, 255),
                    'display_name' => mb_substr($name, 0, 255),
                    'email' => null,
                    'password' => Str::random(64),
                    'telegram_chat_id' => $chatId,
                    'telegram_username' => $username,
                    'telegram_linked_at' => now(),
                    'radar_enabled' => true,
                ]);
                $identity = ExternalIdentity::query()->create([
                    'provider' => 'telegram',
                    'provider_subject' => $telegramUserId,
                    'user_id' => $user->id,
                    'profile' => ['username' => $username],
                ]);
            } else {
                if (! $user) {
                    throw new \RuntimeException('Telegram identity owner no longer exists');
                }
                $user->fill([
                    'telegram_chat_id' => $chatId,
                    'telegram_username' => $username,
                    'telegram_linked_at' => $user->telegram_linked_at ?? now(),
                    'display_name' => $user->display_name ?: (trim((string) $displayName) ?: null),
                ])->save();
                $identity->update(['profile' => ['username' => $username]]);
            }

            return $user->fresh();
        });
    }

    public function find(string $telegramUserId): User
    {
        $identity = ExternalIdentity::query()
            ->with('user')
            ->where('provider', 'telegram')
            ->where('provider_subject', $telegramUserId)
            ->first();

        if (! $identity?->user) {
            throw new HttpResponseException(response()->json([
                'detail' => 'Сначала отправь боту /start в личном чате.',
            ], 401));
        }

        return $identity->user;
    }
}
