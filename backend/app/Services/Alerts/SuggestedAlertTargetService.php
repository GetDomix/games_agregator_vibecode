<?php

namespace App\Services\Alerts;

use App\Models\CurrentGamePrice;
use App\Models\Favorite;
use App\Models\GamePriceObservation;

/** Builds non-persistent target hints from already stored current prices. */
class SuggestedAlertTargetService
{
    /** @param iterable<Favorite> $favorites */
    public function attach(iterable $favorites): void
    {
        $favorites = collect($favorites);
        $gameIds = $favorites->pluck('game_id')->filter()->unique()->values();
        if ($gameIds->isEmpty()) {
            return;
        }

        $prices = CurrentGamePrice::query()
            ->whereIn('game_id', $gameIds)
            ->whereNotNull('min_price_rub')
            ->where('min_price_rub', '>', 0)
            ->where('observed_at', '>=', now()->subDay())
            ->get()
            ->groupBy('game_id');
        $observedLows = GamePriceObservation::query()
            ->selectRaw('game_id, source, offer_kind, MIN(min_price_rub) AS min_price_rub')
            ->whereIn('game_id', $gameIds)
            ->whereNotNull('min_price_rub')
            ->groupBy('game_id', 'source', 'offer_kind')
            ->orderBy('source')
            ->orderBy('offer_kind')
            ->get()
            ->groupBy('game_id');

        foreach ($favorites as $favorite) {
            $alert = $favorite->alert;
            $scopeKeys = $alert
                ? $alert->scopes->map(fn ($scope) => $scope->source.':'.$scope->offer_kind)->all()
                : ['steam:official'];
            $reference = ($prices->get($favorite->game_id, collect()))
                ->filter(fn (CurrentGamePrice $price) => in_array($price->source.':'.$price->offer_kind, $scopeKeys, true))
                ->sortBy([['min_price_rub', 'asc'], ['source', 'asc'], ['offer_kind', 'asc']])
                ->first();
            $favorite->setAttribute('suggested_target', $reference ? [
                'value_rub' => max(1, (int) round((float) $reference->min_price_rub * 0.9)),
                'reference_price_rub' => (float) $reference->min_price_rub,
                'reduction_percent' => 10,
                'source' => $reference->source,
                'offer_kind' => $reference->offer_kind,
                'observed_at' => $reference->observed_at?->toIso8601String(),
                'basis' => 'current_price_minus_10_percent',
            ] : null);
            $favorite->setAttribute('observed_lows', ($observedLows->get($favorite->game_id, collect()))
                ->map(fn (GamePriceObservation $row) => [
                    'source' => $row->source,
                    'offer_kind' => $row->offer_kind,
                    'price_rub' => (float) $row->min_price_rub,
                ])->values()->all());
        }
    }
}
