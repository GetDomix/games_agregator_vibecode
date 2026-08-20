<?php

namespace App\Services\Alerts;

use App\Jobs\DeliverAlertEventJob;
use App\Models\AlertDelivery;
use App\Models\AlertEvent;
use App\Models\FavoriteAlert;
use App\Models\Game;
use App\Models\GamePriceObservation;
use App\Models\GameSourceState;
use App\Services\Notifications\SiteNotificationService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class AlertEvaluationService
{
    public function __construct(private readonly SiteNotificationService $siteNotifications) {}

    /** @param array<int, int> $freshObservationIds */
    public function evaluate(Game $game, string $refreshedSource, array $freshObservationIds = []): int
    {
        if (! in_array($refreshedSource, GameSourceState::SOURCES, true)) {
            throw new \InvalidArgumentException('Unknown price source');
        }
        $freshIds = array_values(array_unique(array_filter(array_map('intval', $freshObservationIds), fn (int $id) => $id > 0)));
        if ($freshIds === []) {
            return 0;
        }
        // An event is always based on a row just inserted by this refresh. Never
        // use current_game_prices here: it can already have been superseded.
        $fresh = GamePriceObservation::query()
            ->whereIn('id', $freshIds)
            ->where('game_id', $game->id)
            ->where('source', $refreshedSource)
            ->whereNotNull('min_price_rub')
            ->get();
        if ($fresh->isEmpty()) {
            return 0;
        }
        $alerts = FavoriteAlert::query()
            ->where('status', 'active')
            ->whereHas('favorite', fn ($query) => $query->where('game_id', $game->id))
            ->with(['favorite.user', 'scopes'])
            ->get();
        // Old merges could leave an active generation occupied by imported
        // history. Normalize it before selecting a candidate, so the immutable
        // expectation below belongs to a free event cycle.
        $alerts = $alerts->map(fn (FavoriteAlert $alert) => $this->normalizeActiveCycle($alert));
        $priorMinimums = $this->priorMinimums($game, $refreshedSource, $fresh);
        $created = 0;

        foreach ($alerts as $alert) {
            $candidate = match ($alert->condition_type) {
                'new_low' => $this->newLowCandidate($alert, $fresh, $priorMinimums),
                'discount_percent' => $this->discountCandidate($alert, $fresh, $refreshedSource),
                default => $this->targetCandidate($alert, $fresh),
            };
            if (! $candidate) {
                continue;
            }

            $event = $this->createEvent($alert->id, $candidate, $this->expectation($alert));
            if ($event) {
                $created++;
            }
        }

        return $created;
    }

    /** @return array{cycle:int,condition_type:string,target_value:float|int|null,scopes:list<string>} */
    private function expectation(FavoriteAlert $alert): array
    {
        $scopes = $alert->scopes
            ->map(fn ($scope) => $scope->source.':'.$scope->offer_kind)
            ->sort()->values()->all();

        return [
            'cycle' => (int) $alert->cycle,
            'condition_type' => (string) $alert->condition_type,
            'target_value' => $alert->target_value === null ? null : (float) $alert->target_value,
            'scopes' => $scopes,
        ];
    }

    private function targetCandidate(FavoriteAlert $alert, Collection $fresh): ?GamePriceObservation
    {
        if ($alert->target_value === null) {
            return null;
        }
        $candidate = $this->lowestMatchingPrice($alert, $fresh);

        return $candidate && (float) $candidate->min_price_rub <= (float) $alert->target_value ? $candidate : null;
    }

    private function discountCandidate(FavoriteAlert $alert, Collection $fresh, string $refreshedSource): ?GamePriceObservation
    {
        if ($refreshedSource !== 'steam' || $alert->target_value === null) {
            return null;
        }
        $candidate = $this->lowestMatchingPrice($alert, $fresh);

        return $candidate && $candidate->source === 'steam' && $candidate->offer_kind === 'official'
            && (int) $candidate->discount_percent >= (int) $alert->target_value ? $candidate : null;
    }

    private function lowestMatchingPrice(FavoriteAlert $alert, Collection $prices): ?GamePriceObservation
    {
        $scopeKeys = $alert->scopes->map(fn ($scope) => $scope->source.':'.$scope->offer_kind)->all();

        return $prices
            ->filter(fn (GamePriceObservation $price) => $price->min_price_rub !== null
                && in_array($price->source.':'.$price->offer_kind, $scopeKeys, true))
            ->sortBy([
                ['min_price_rub', 'asc'],
                ['source', 'asc'],
                ['offer_kind', 'asc'],
                ['id', 'asc'],
            ])
            ->first();
    }

    /** @param array<int, float|null> $priorMinimums */
    private function newLowCandidate(FavoriteAlert $alert, Collection $fresh, array $priorMinimums): ?GamePriceObservation
    {
        $scopeKeys = $alert->scopes->map(fn ($scope) => $scope->source.':'.$scope->offer_kind)->all();

        return $fresh
            ->filter(fn (GamePriceObservation $price) => in_array($price->source.':'.$price->offer_kind, $scopeKeys, true))
            ->filter(fn (GamePriceObservation $price) => ($priorMinimums[$price->id] ?? null) !== null
                && (float) $price->min_price_rub < (float) $priorMinimums[$price->id])
            ->sortBy([['min_price_rub', 'asc'], ['source', 'asc'], ['offer_kind', 'asc'], ['id', 'asc']])
            ->first();
    }

    /** @return array<int, float|null> */
    private function priorMinimums(Game $game, string $source, Collection $fresh): array
    {
        $largestFreshId = (int) $fresh->max('id');
        $priorByKind = GamePriceObservation::query()
            ->where('game_id', $game->id)
            ->where('source', $source)
            ->where('id', '<', $largestFreshId)
            ->whereNotNull('min_price_rub')
            ->get(['id', 'offer_kind', 'min_price_rub'])
            ->groupBy('offer_kind');

        return $fresh->mapWithKeys(function (GamePriceObservation $price) use ($priorByKind): array {
            $minimum = ($priorByKind->get($price->offer_kind, collect()))
                ->filter(fn (GamePriceObservation $prior) => $prior->id < $price->id)
                ->min('min_price_rub');

            return [$price->id => $minimum === null ? null : (float) $minimum];
        })->all();
    }

    private function normalizeActiveCycle(FavoriteAlert $snapshot): FavoriteAlert
    {
        return DB::transaction(function () use ($snapshot): FavoriteAlert {
            $alert = FavoriteAlert::query()->with(['favorite.user', 'scopes'])->lockForUpdate()->findOrFail($snapshot->id);
            if ($alert->status === 'active' && AlertEvent::query()
                ->where('favorite_alert_id', $alert->id)
                ->where('alert_cycle', $alert->cycle)
                ->lockForUpdate()
                ->exists()) {
                $maxCycle = (int) AlertEvent::query()
                    ->where('favorite_alert_id', $alert->id)
                    ->lockForUpdate()
                    ->get(['alert_cycle'])
                    ->max('alert_cycle');
                $alert->forceFill(['cycle' => $maxCycle + 1, 'triggered_at' => null])->save();
            }

            return $alert;
        });
    }

    /** @param array{cycle:int,condition_type:string,target_value:float|int|null,scopes:list<string>} $expectation */
    private function createEvent(int $alertId, GamePriceObservation $price, array $expectation): ?AlertEvent
    {
        return DB::transaction(function () use ($alertId, $price, $expectation): ?AlertEvent {
            $alert = FavoriteAlert::query()
                ->with(['favorite.user', 'scopes'])
                ->lockForUpdate()
                ->find($alertId);

            if (! $alert || $alert->status !== 'active' || ! $alert->favorite?->user
                || ($alert->condition_type !== 'new_low' && $alert->target_value === null)
                || $this->expectation($alert) !== $expectation) {
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
                    'offer_title' => $price->offer_title,
                    'offer_url' => $price->offer_url,
                    'observed_at' => $price->observed_at,
                ]
            );

            if (! $event->wasRecentlyCreated) {
                return null;
            }

            $alert->update(['status' => 'triggered', 'triggered_at' => now()]);
            $this->siteNotifications->publishAlert($event, $alert);
            $canDeliver = $alert->favorite->user->telegram_chat_id && $alert->favorite->user->radar_enabled;
            AlertDelivery::query()->create([
                'alert_event_id' => $event->id,
                'status' => $canDeliver ? AlertDelivery::STATUS_PENDING : AlertDelivery::STATUS_SKIPPED,
                'last_error' => $canDeliver ? null : ($alert->favorite->user->telegram_chat_id
                    ? 'Telegram radar is disabled'
                    : 'Telegram chat is not linked'),
            ]);

            if ($canDeliver) {
                DeliverAlertEventJob::dispatch($event->id)->afterCommit();
            }

            return $event;
        });
    }
}
