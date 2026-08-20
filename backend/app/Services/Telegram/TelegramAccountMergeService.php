<?php

namespace App\Services\Telegram;

use App\Models\AlertEvent;
use App\Models\ExternalIdentity;
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

            // Merge lock order is user ids, then favorite ids, then alert ids,
            // then event rows. Settings follows favorite -> alert; evaluator
            // only locks alert -> event, so it commits before merge can remap.
            $users = User::query()->whereIn('id', [$website->id, $telegram->id])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $website = $users->get($website->id);
            $telegram = $users->get($telegram->id);
            if (
                $website->telegram_chat_id !== null
                && $telegram->telegram_chat_id !== null
                && $website->telegram_chat_id !== $telegram->telegram_chat_id
            ) {
                throw new RuntimeException('The website account is already linked to another Telegram chat.');
            }

            $telegramIdentities = ExternalIdentity::query()
                ->where('provider', 'telegram')
                ->whereIn('user_id', [$website->id, $telegram->id])
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('user_id');
            $websiteTelegramIdentity = $telegramIdentities->get($website->id);
            $telegramTelegramIdentity = $telegramIdentities->get($telegram->id);
            if (
                $websiteTelegramIdentity
                && $telegramTelegramIdentity
                && $websiteTelegramIdentity->provider_subject !== $telegramTelegramIdentity->provider_subject
            ) {
                throw new RuntimeException('The website account is already linked to another Telegram identity.');
            }

            $moved = 0;

            $sourceIds = Favorite::query()->where('user_id', $telegram->id)->orderBy('id')->pluck('id');
            foreach ($sourceIds as $sourceId) {
                // User rows are already locked in id order, serializing a merge
                // pair. Resolve ids without a lone favorite lock, then acquire
                // every participating favorite in one ascending-order query.
                $source = Favorite::query()->find($sourceId);
                if (! $source || $source->user_id !== $telegram->id) {
                    continue;
                }
                $target = Favorite::query()
                    ->where('user_id', $website->id)
                    ->where('appid', $source->appid)
                    ->first();

                if (! $target) {
                    $source = Favorite::query()->whereKey($source->id)->lockForUpdate()->first();
                    if (! $source || $source->user_id !== $telegram->id) {
                        continue;
                    }
                    $source->user_id = $website->id;
                    $source->save();
                    $moved++;

                    continue;
                }

                $lockedFavorites = Favorite::query()->whereIn('id', [$source->id, $target->id])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
                $source = $lockedFavorites->get($source->id);
                $target = $lockedFavorites->get($target->id);
                $alerts = FavoriteAlert::query()->whereIn('favorite_id', [$source->id, $target->id])->orderBy('id')->lockForUpdate()->get()->keyBy('favorite_id');
                $source->setRelation('alert', $alerts->get($source->id)?->load('scopes'));
                $target->setRelation('alert', $alerts->get($target->id)?->load('scopes'));

                // A legacy number is not allowed to leak into an existing
                // condition-aware alert configuration.
                if ($target->alert === null && $target->target_price_rub === null && $source->target_price_rub !== null) {
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

            // A merge must never turn an unread notification into a read one.
            // Keep the earlier cursor; at worst this can re-show an item that was
            // already read on one of the two profiles, but it cannot hide one.
            $website->forceFill([
                'notifications_read_through_id' => min(
                    (int) $website->notifications_read_through_id,
                    (int) $telegram->notifications_read_through_id,
                ),
            ])->save();

            DB::table('search_histories')->where('user_id', $telegram->id)->update(['user_id' => $website->id]);
            DB::table('price_snapshots')->where('user_id', $telegram->id)->update(['user_id' => $website->id]);
            DB::table('partner_clicks')->where('user_id', $telegram->id)->update(['user_id' => $website->id]);
            DB::table('radar_events')->where('user_id', $telegram->id)->update(['user_id' => $website->id]);
            DB::table('alert_events')->where('user_id', $telegram->id)->update(['user_id' => $website->id]);
            DB::table('site_notifications')->where('recipient_user_id', $telegram->id)->update(['recipient_user_id' => $website->id]);
            DB::table('oidc_transactions')->where('user_id', $telegram->id)->update(['user_id' => $website->id]);
            ExternalIdentity::query()->where('user_id', $telegram->id)->orderBy('id')->lockForUpdate()->update(['user_id' => $website->id]);

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

        // Existing website settings are an atomic user decision. Do not combine
        // conditions, thresholds or scopes from the duplicate account.
        if (! $targetHadAlert) {
            foreach ($sourceAlert->scopes as $scope) {
                $targetAlert->scopes()->firstOrCreate([
                    'source' => $scope->source,
                    'offer_kind' => $scope->offer_kind,
                ]);
            }
            $target->forceFill([
                'target_price_rub' => $sourceAlert->condition_type === 'target_price' ? $sourceAlert->target_value : null,
            ])->save();
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
            ->whereIn('favorite_alert_id', $targetHadAlert ? [$targetAlert->id, $sourceAlert->id] : [$sourceAlert->id])
            ->orderBy('favorite_alert_id')
            ->orderBy('alert_cycle')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
        $sourceEvents = $events->where('favorite_alert_id', $sourceAlert->id)->values();

        // alert_cycle is the per-alert dedup key. Reserve 0..N-1 for imported
        // history and shift every existing target event first (descending avoids
        // temporary unique-key collisions). The target's current semantic state
        // is preserved; only its internal dedup generation advances with its
        // corresponding current event, if any.
        if ($targetHadAlert && $sourceEvents->isNotEmpty()) {
            $offset = $sourceEvents->count();
            $events->where('favorite_alert_id', $targetAlert->id)
                ->sortByDesc(fn (AlertEvent $event) => [(int) $event->alert_cycle, (int) $event->id])
                ->each(fn (AlertEvent $event) => $event->forceFill(['alert_cycle' => (int) $event->alert_cycle + $offset])->save());
            $targetAlert->forceFill(['cycle' => (int) $targetAlert->cycle + $offset])->save();
        }

        foreach ($sourceEvents as $index => $event) {
            $cycle = $targetHadAlert ? $index : (int) $event->alert_cycle;
            $event->forceFill([
                'favorite_alert_id' => $targetAlert->id,
                'alert_cycle' => $cycle,
                'user_id' => $targetFavorite->user_id,
                'favorite_id' => $targetFavorite->id,
            ])->save();
        }

        $this->ensureActiveCycleIsFree($targetAlert);

    }

    private function ensureActiveCycleIsFree(FavoriteAlert $alert): void
    {
        if ($alert->status !== 'active' || ! AlertEvent::query()
            ->where('favorite_alert_id', $alert->id)
            ->where('alert_cycle', $alert->cycle)
            ->lockForUpdate()
            ->exists()) {
            return;
        }
        $maxCycle = (int) AlertEvent::query()
            ->where('favorite_alert_id', $alert->id)
            ->lockForUpdate()
            ->get(['alert_cycle'])
            ->max('alert_cycle');
        $alert->forceFill(['cycle' => $maxCycle + 1, 'triggered_at' => null])->save();
    }
}
