<?php

namespace App\Services\Pricing;

use App\Models\CurrentGamePrice;
use App\Models\Game;
use App\Models\GamePriceObservation;
use App\Models\GameSourceState;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

class GamePriceHistoryService
{
    private const MINIMUM_CHECKS_FOR_VERDICT = 4;

    private const MINIMUM_OBSERVED_DAYS_FOR_VERDICT = 7;

    /** @return array<string, mixed> */
    public function overview(Game $game, int $days): array
    {
        $since = now()->subDays($days);
        $observations = $this->eligibleObservations($game)
            ->where('observed_at', '>=', $since)
            ->orderBy('observed_at')
            ->get();
        $current = $this->eligibleCurrentPrices($game)
            ->orderBy('min_price_rub')
            ->first();

        $prices = $observations
            ->map(fn (GamePriceObservation $row) => (float) $row->min_price_rub)
            ->sort()
            ->values();
        $median = $this->median($prices);
        $minimum = $prices->isEmpty() ? null : (float) $prices->first();
        $checks = $observations
            ->map(fn (GamePriceObservation $row) => $row->observed_at?->toIso8601String())
            ->filter()
            ->unique()
            ->count();
        $firstObservedAt = $this->eligibleObservations($game)->min('observed_at');
        $observedDays = $firstObservedAt
            ? min($days, CarbonImmutable::parse($firstObservedAt)->startOfDay()->diffInDays(now()->startOfDay()) + 1)
            : 0;
        $currentPrice = $current ? (float) $current->min_price_rub : null;
        $sufficient = $checks >= self::MINIMUM_CHECKS_FOR_VERDICT
            && $observedDays >= self::MINIMUM_OBSERVED_DAYS_FOR_VERDICT;

        return [
            'period_days' => $days,
            'available_periods' => [30, 90, 365],
            'current' => $current ? [
                'price_rub' => $currentPrice,
                'source' => $current->source,
                'offer_kind' => $current->offer_kind,
                'observed_at' => $current->observed_at?->toIso8601String(),
            ] : null,
            'statistics' => [
                'minimum_price_rub' => $minimum === null ? null : round($minimum, 2),
                'median_price_rub' => $median === null ? null : round($median, 2),
            ],
            'coverage' => [
                'observations' => $observations->count(),
                'checks' => $checks,
                'observed_days' => $observedDays,
                'started_at' => $firstObservedAt
                    ? CarbonImmutable::parse($firstObservedAt)->toIso8601String()
                    : null,
                'sufficient' => $sufficient,
            ],
            'verdict' => $this->verdict($currentPrice, $median, $sufficient),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    public function changes(Game $game, int $days): array
    {
        $since = now()->subDays($days);
        $changes = [];

        foreach ([
            [GameSourceState::SOURCE_STEAM, CurrentGamePrice::OFFER_KIND_OFFICIAL],
            [GameSourceState::SOURCE_PLATI, CurrentGamePrice::OFFER_KIND_KEY],
            [GameSourceState::SOURCE_GGSEL, CurrentGamePrice::OFFER_KIND_KEY],
        ] as [$source, $kind]) {
            $stream = $game->priceObservations()
                ->where('source', $source)
                ->where('offer_kind', $kind);
            $previous = (clone $stream)
                ->where('observed_at', '<', $since)
                ->latest('observed_at')
                ->first();
            $rows = (clone $stream)
                ->where('observed_at', '>=', $since)
                ->orderBy('observed_at')
                ->get();

            foreach ($rows as $row) {
                if ($previous && (float) $previous->min_price_rub !== (float) $row->min_price_rub) {
                    $oldPrice = (float) $previous->min_price_rub;
                    $newPrice = (float) $row->min_price_rub;
                    $changes[] = [
                        'source' => $source,
                        'offer_kind' => $kind,
                        'previous_price_rub' => round($oldPrice, 2),
                        'price_rub' => round($newPrice, 2),
                        'change_percent' => $oldPrice > 0
                            ? (int) round((($newPrice - $oldPrice) / $oldPrice) * 100)
                            : null,
                        'observed_at' => $row->observed_at?->toIso8601String(),
                    ];
                }

                $previous = $row;
            }
        }

        return collect($changes)
            ->sortByDesc('observed_at')
            ->take(200)
            ->values()
            ->all();
    }

    private function eligibleObservations(Game $game): HasMany
    {
        return $game->priceObservations()->where(function (Builder $query) {
            $query->where(function (Builder $steam) {
                $steam->where('source', GameSourceState::SOURCE_STEAM)
                    ->where('offer_kind', CurrentGamePrice::OFFER_KIND_OFFICIAL);
            })->orWhere(function (Builder $keys) {
                $keys->whereIn('source', [GameSourceState::SOURCE_PLATI, GameSourceState::SOURCE_GGSEL])
                    ->where('offer_kind', CurrentGamePrice::OFFER_KIND_KEY);
            });
        });
    }

    private function eligibleCurrentPrices(Game $game): HasMany
    {
        return $game->currentPrices()->where(function (Builder $query) {
            $query->where(function (Builder $steam) {
                $steam->where('source', GameSourceState::SOURCE_STEAM)
                    ->where('offer_kind', CurrentGamePrice::OFFER_KIND_OFFICIAL);
            })->orWhere(function (Builder $keys) {
                $keys->whereIn('source', [GameSourceState::SOURCE_PLATI, GameSourceState::SOURCE_GGSEL])
                    ->where('offer_kind', CurrentGamePrice::OFFER_KIND_KEY);
            });
        });
    }

    private function median(Collection $prices): ?float
    {
        $count = $prices->count();
        if ($count === 0) {
            return null;
        }

        $middle = intdiv($count, 2);
        if ($count % 2 === 1) {
            return (float) $prices[$middle];
        }

        return ((float) $prices[$middle - 1] + (float) $prices[$middle]) / 2;
    }

    private function verdict(?float $current, ?float $median, bool $sufficient): string
    {
        if ($current === null || $median === null || ! $sufficient) {
            return 'insufficient';
        }
        if ($median <= 0) {
            return $current <= 0 ? 'low' : 'typical';
        }

        $ratio = $current / $median;
        if ($ratio <= 0.9) {
            return 'low';
        }
        if ($ratio >= 1.15) {
            return 'high';
        }

        return 'typical';
    }
}
