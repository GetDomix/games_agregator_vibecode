<?php

namespace App\Services;

use App\Models\Favorite;
use App\Models\FavoriteAlert;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class TelegramAccountMergeService
{
    /** Merge a Telegram-first account into the signed-in website account. */
    public function merge(User $website, User $telegram): array
    {
        return DB::transaction(function () use ($website, $telegram): array {
            $moved = 0;

            foreach (Favorite::query()->where('user_id', $telegram->id)->with('alert.scopes')->get() as $source) {
                $target = Favorite::query()
                    ->where('user_id', $website->id)
                    ->where('appid', $source->appid)
                    ->with('alert.scopes')
                    ->first();

                if (! $target) {
                    $source->user_id = $website->id;
                    $source->save();
                    $moved++;

                    continue;
                }

                if ($target->target_price_rub === null && $source->target_price_rub !== null) {
                    $target->target_price_rub = $source->target_price_rub;
                    $target->save();
                }

                $this->mergeAlerts($target, $source);
                $source->delete();
            }

            // A website account can inherit an existing bot connection, so alerts
            // keep reaching the same Telegram chat after the duplicate is removed.
            if ($website->telegram_chat_id === null && $telegram->telegram_chat_id !== null) {
                $chatId = $telegram->telegram_chat_id;
                $username = $telegram->telegram_username;
                $linkedAt = $telegram->telegram_linked_at;
                $radarEnabled = $telegram->radar_enabled;

                // The chat id is unique. Release it from the duplicate account
                // before assigning it to the surviving one.
                $telegram->forceFill([
                    'telegram_chat_id' => null,
                    'telegram_username' => null,
                    'telegram_linked_at' => null,
                ])->save();

                $website->forceFill([
                    'telegram_chat_id' => $chatId,
                    'telegram_username' => $username,
                    'telegram_linked_at' => $linkedAt,
                    'radar_enabled' => $radarEnabled,
                ])->save();
            }

            $telegram->tokens()->delete();
            $telegram->delete();

            return ['favorites_moved' => $moved];
        });
    }

    private function mergeAlerts(Favorite $target, Favorite $source): void
    {
        $sourceAlert = $source->alert;

        if (! $sourceAlert) {
            return;
        }

        $targetAlert = $target->alert ?? FavoriteAlert::query()->create([
            'favorite_id' => $target->id,
            'condition_type' => $sourceAlert->condition_type,
            'target_value' => $sourceAlert->target_value,
            'status' => $sourceAlert->status,
            'triggered_at' => $sourceAlert->triggered_at,
        ]);

        if ($targetAlert->target_value === null && $sourceAlert->target_value !== null) {
            $targetAlert->target_value = $sourceAlert->target_value;
            $targetAlert->save();
        }

        foreach ($sourceAlert->scopes as $scope) {
            $targetAlert->scopes()->firstOrCreate([
                'source' => $scope->source,
                'offer_kind' => $scope->offer_kind,
            ]);
        }
    }
}
