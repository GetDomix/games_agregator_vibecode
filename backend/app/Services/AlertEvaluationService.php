<?php

namespace App\Services;

use App\Jobs\DeliverAlertEventJob;
use App\Models\AlertDelivery;
use App\Models\AlertEvent;
use App\Models\CurrentGamePrice;
use App\Models\FavoriteAlert;
use App\Models\Game;
use Illuminate\Support\Facades\DB;

class AlertEvaluationService
{
    public function evaluate(Game $game): int
    {
        $alerts = FavoriteAlert::query()
            ->where('status', 'active')
            ->whereNotNull('target_value')
            ->whereHas('favorite', fn ($query) => $query->where('game_id', $game->id))
            ->with(['favorite.user', 'scopes'])
            ->get();
        $prices = CurrentGamePrice::query()->where('game_id', $game->id)->get();
        $created = 0;

        foreach ($alerts as $alert) {
            $candidate = $this->lowestMatchingPrice($alert, $prices);
            if (! $candidate || (float) $candidate->min_price_rub > (float) $alert->target_value) {
                continue;
            }

            $event = $this->createEvent($alert->id, $candidate);
            if ($event) {
                $created++;
            }
        }

        return $created;
    }

    private function lowestMatchingPrice(FavoriteAlert $alert, $prices): ?CurrentGamePrice
    {
        $scopeKeys = $alert->scopes->map(fn ($scope) => $scope->source.':'.$scope->offer_kind)->all();

        return $prices
            ->filter(fn (CurrentGamePrice $price) => $price->min_price_rub !== null
                && in_array($price->source.':'.$price->offer_kind, $scopeKeys, true))
            ->sortBy([
                ['min_price_rub', 'asc'],
                ['source', 'asc'],
                ['offer_kind', 'asc'],
            ])
            ->first();
    }

    private function createEvent(int $alertId, CurrentGamePrice $price): ?AlertEvent
    {
        return DB::transaction(function () use ($alertId, $price): ?AlertEvent {
            $alert = FavoriteAlert::query()
                ->with(['favorite.user'])
                ->lockForUpdate()
                ->find($alertId);

            if (! $alert || $alert->status !== 'active' || $alert->target_value === null || ! $alert->favorite?->user) {
                return null;
            }

            $event = AlertEvent::query()->firstOrCreate(
                ['favorite_alert_id' => $alert->id, 'alert_cycle' => $alert->cycle],
                [
                    'user_id' => $alert->favorite->user_id,
                    'favorite_id' => $alert->favorite_id,
                    'game_id' => $price->game_id,
                    'source' => $price->source,
                    'offer_kind' => $price->offer_kind,
                    'offer_price_rub' => $price->min_price_rub,
                    'offer_title' => $price->cheapest_offer_title,
                    'offer_url' => $price->cheapest_offer_url,
                    'observed_at' => $price->observed_at,
                ]
            );

            if (! $event->wasRecentlyCreated) {
                return null;
            }

            $alert->update(['status' => 'triggered', 'triggered_at' => now()]);
            AlertDelivery::query()->create([
                'alert_event_id' => $event->id,
                'status' => $alert->favorite->user->telegram_chat_id
                    ? AlertDelivery::STATUS_PENDING
                    : AlertDelivery::STATUS_SKIPPED,
                'last_error' => $alert->favorite->user->telegram_chat_id ? null : 'Telegram chat is not linked',
            ]);

            if ($alert->favorite->user->telegram_chat_id) {
                DeliverAlertEventJob::dispatch($event->id)->afterCommit();
            }

            return $event;
        });
    }
}
