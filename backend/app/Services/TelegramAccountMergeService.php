<?php

namespace App\Services;

use App\Models\AlertEvent;
use App\Models\Favorite;
use App\Models\FavoriteAlert;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class TelegramAccountMergeService
{
    /** Merge a Telegram-first account into the signed-in website account. */
    public function merge(User $website, User $telegram): array
    {
        return DB::transaction(function () use ($website, $telegram): array {
            if ($website->is($telegram)) {
                return ['favorites_moved' => 0];
            }

            $website->refresh();
            $telegram->refresh();
            if (
                $website->telegram_chat_id !== null
                && $telegram->telegram_chat_id !== null
                && $website->telegram_chat_id !== $telegram->telegram_chat_id
            ) {
                throw new RuntimeException('The website account is already linked to another Telegram chat.');
            }

            $websiteTelegramIdentity = $website->externalIdentities()->where('provider', 'telegram')->first();
            $telegramTelegramIdentity = $telegram->externalIdentities()->where('provider', 'telegram')->first();
            if (
                $websiteTelegramIdentity
                && $telegramTelegramIdentity
                && $websiteTelegramIdentity->provider_subject !== $telegramTelegramIdentity->provider_subject
            ) {
                throw new RuntimeException('The website account is already linked to another Telegram identity.');
            }

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

            DB::table('search_histories')->where('user_id', $telegram->id)->update(['user_id' => $website->id]);
            DB::table('price_snapshots')->where('user_id', $telegram->id)->update(['user_id' => $website->id]);
            DB::table('partner_clicks')->where('user_id', $telegram->id)->update(['user_id' => $website->id]);
            DB::table('radar_events')->where('user_id', $telegram->id)->update(['user_id' => $website->id]);
            DB::table('alert_events')->where('user_id', $telegram->id)->update(['user_id' => $website->id]);
            DB::table('oidc_transactions')->where('user_id', $telegram->id)->update(['user_id' => $website->id]);
            $telegram->externalIdentities()->update(['user_id' => $website->id]);

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

        $targetHadAlert = $target->alert !== null;
        $targetAlert = $target->alert ?? FavoriteAlert::query()->create([
            'favorite_id' => $target->id,
            'condition_type' => $sourceAlert->condition_type,
            'target_value' => $sourceAlert->target_value,
            'status' => $sourceAlert->status,
            'cycle' => $sourceAlert->cycle,
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

        $this->moveAlertHistory($targetAlert, $sourceAlert, $target, $targetHadAlert);
    }

    private function moveAlertHistory(
        FavoriteAlert $targetAlert,
        FavoriteAlert $sourceAlert,
        Favorite $targetFavorite,
        bool $targetHadAlert
    ): void {
        $events = AlertEvent::query()
            ->where('favorite_alert_id', $sourceAlert->id)
            ->orderBy('alert_cycle')
            ->orderBy('id')
            ->get();

        if ($events->isEmpty()) {
            return;
        }

        $nextCycle = $targetHadAlert
            ? max(
                (int) $targetAlert->cycle,
                (int) (AlertEvent::query()->where('favorite_alert_id', $targetAlert->id)->max('alert_cycle') ?? -1)
            ) + 1
            : null;

        foreach ($events as $event) {
            $cycle = $nextCycle === null ? (int) $event->alert_cycle : $nextCycle++;
            $event->forceFill([
                'favorite_alert_id' => $targetAlert->id,
                'alert_cycle' => $cycle,
                'user_id' => $targetFavorite->user_id,
                'favorite_id' => $targetFavorite->id,
            ])->save();
        }

        $latestCycle = (int) AlertEvent::query()
            ->where('favorite_alert_id', $targetAlert->id)
            ->max('alert_cycle');
        if ((int) $targetAlert->cycle < $latestCycle) {
            $targetAlert->forceFill(['cycle' => $latestCycle])->save();
        }
    }
}
