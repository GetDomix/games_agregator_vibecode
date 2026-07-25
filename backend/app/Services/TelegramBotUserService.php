<?php

namespace App\Services;

use App\Models\ExternalIdentity;
use App\Models\User;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TelegramBotUserService
{
    public function resolve(string $telegramUserId, string $chatId, ?string $username, ?string $displayName): User
    {
        return DB::transaction(function () use ($telegramUserId, $chatId, $username, $displayName): User {
            if ($telegramUserId !== $chatId) {
                throw new HttpResponseException(response()->json([
                    'detail' => 'Аккаунт Игроскана доступен только в личном чате с ботом.',
                ], 422));
            }

            $identity = ExternalIdentity::query()
                ->with('user')
                ->where('provider', 'telegram')
                ->where('provider_subject', $telegramUserId)
                ->lockForUpdate()
                ->first();
            $user = $identity?->user;
            if (! $user) {
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
            } else {
                $user->fill([
                    'telegram_chat_id' => $chatId,
                    'telegram_username' => $username,
                    'telegram_linked_at' => $user->telegram_linked_at ?? now(),
                    'display_name' => $user->display_name ?: (trim((string) $displayName) ?: null),
                    'radar_enabled' => true,
                ])->save();
            }

            ExternalIdentity::query()->updateOrCreate(
                ['provider' => 'telegram', 'provider_subject' => $telegramUserId],
                ['user_id' => $user->id, 'profile' => ['username' => $username]]
            );

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
