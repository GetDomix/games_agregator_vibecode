<?php

namespace App\Services;

use App\Data\PriceSourceResult;
use App\Models\CurrentGamePrice;
use App\Models\Game;
use App\Models\GamePriceObservation;
use App\Models\GameSourceState;
use App\Models\SteamRegionalPrice;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

class GamePriceRefreshService
{
    public function __construct(
        private readonly PriceSourceRegistry $sources,
        private readonly ?AlertEvaluationService $alerts = null,
    ) {}

    public function refresh(Game $game, string $source): int
    {
        $state = GameSourceState::query()->firstOrCreate(['game_id' => $game->id, 'source' => $source]);
        if ($source !== GameSourceState::SOURCE_STEAM && ! $game->isReleased()) {
            // Это не выполняющаяся загрузка: маркетплейсы ждут, пока Steam
            // подтвердит релиз. Pending здесь оставлял интерфейс со спиннером
            // навсегда, хотя задание уже было завершено.
            $state->forceFill([
                'last_attempt_at' => now(),
                'status' => GameSourceState::STATUS_STALE,
                'next_refresh_at' => now()->addHours(24),
            ])->save();

            return 0;
        }
        $state->forceFill(['last_attempt_at' => now(), 'status' => GameSourceState::STATUS_PENDING])->save();
        $result = $this->sources->for($source)->refresh($game);

        return $this->apply($game->fresh(), $state->fresh(), $result);
    }

    public function apply(Game $game, GameSourceState $state, PriceSourceResult $result): int
    {
        DB::transaction(function () use ($game, $state, $result) {
            $wasReleased = $game->isReleased();
            $gameValues = array_filter([
                'name' => $result->gameName,
                'header_image' => $result->headerImage,
                'release_status' => $result->releaseStatus,
                'release_date' => $result->releaseDate,
            ], fn ($value) => $value !== null);
            $game->fill($gameValues);
            $game->save();

            if ($result->source === GameSourceState::SOURCE_STEAM) {
                $regions = [];
                foreach ($result->regionalPrices as $regional) {
                    $region = (string) ($regional['region'] ?? '');
                    if ($region === '' || ! isset($regional['amount'], $regional['currency'])) {
                        continue;
                    }
                    $regions[] = $region;
                    SteamRegionalPrice::query()->updateOrCreate(
                        ['game_id' => $game->id, 'region' => $region],
                        [
                            'currency' => (string) $regional['currency'],
                            'price_amount' => $regional['amount'],
                            'price_rub' => $regional['price_rub'] ?? null,
                            'observed_at' => now(),
                        ]
                    );
                }
                $regionalQuery = SteamRegionalPrice::query()->where('game_id', $game->id);
                $regions === [] ? $regionalQuery->delete() : $regionalQuery->whereNotIn('region', $regions)->delete();
            }

            $kinds = [];
            foreach ($result->offerGroups as $group) {
                $kind = (string) $group['kind'];
                $kinds[] = $kind;
                $cheapest = $group['cheapest'];
                $popular = $group['popular'];
                $values = [
                    'min_price_rub' => $group['min_price_rub'],
                    'avg_price_rub' => $group['avg_price_rub'],
                    'currency_prices' => $group['currency_prices'] ?? null,
                    'offer_count' => $group['offer_count'],
                    'cheapest_offer_title' => $cheapest['title'] ?? null,
                    'cheapest_offer_url' => $cheapest['url'] ?? null,
                    'popular_offer_title' => $popular['title'] ?? null,
                    'popular_offer_url' => $popular['url'] ?? null,
                    'popular_offer_price_rub' => $popular['price_rub'] ?? null,
                    'popular_offer_sales' => $popular['sales'] ?? null,
                    'observed_at' => now(),
                ];
                if ($result->source === GameSourceState::SOURCE_STEAM) {
                    $values['discount_percent'] = $result->discountPercent;
                    $values['price_initial_rub'] = $result->priceInitialRub;
                }
                CurrentGamePrice::query()->updateOrCreate(
                    ['game_id' => $game->id, 'source' => $result->source, 'offer_kind' => $kind],
                    $values
                );
                GamePriceObservation::query()->create([
                    'game_id' => $game->id,
                    'source' => $result->source,
                    'offer_kind' => $kind,
                    'min_price_rub' => $group['min_price_rub'],
                    'offer_title' => $cheapest['title'] ?? null,
                    'offer_url' => $cheapest['url'] ?? null,
                    'offer_sales' => $cheapest['sales'] ?? null,
                    'observed_at' => now(),
                ]);
            }
            $obsolete = CurrentGamePrice::query()->where('game_id', $game->id)->where('source', $result->source);
            $kinds === [] ? $obsolete->delete() : $obsolete->whereNotIn('offer_kind', $kinds)->delete();

            $state->forceFill([
                'last_success_at' => now(),
                'next_refresh_at' => $this->nextSuccessfulRefresh($game, $result->source),
                'status' => GameSourceState::STATUS_FRESH,
                'last_error' => null,
                'consecutive_failures' => 0,
            ])->save();

            if (! $wasReleased && $game->isReleased()) {
                foreach ([GameSourceState::SOURCE_PLATI, GameSourceState::SOURCE_GGSEL] as $marketplace) {
                    GameSourceState::query()->updateOrCreate(
                        ['game_id' => $game->id, 'source' => $marketplace],
                        ['next_refresh_at' => now(), 'status' => GameSourceState::STATUS_PENDING]
                    );
                }
            }
        });

        return $this->alerts?->evaluate($game->fresh()) ?? 0;
    }

    public function recordFailure(Game $game, string $source, \Throwable $error): void
    {
        $state = GameSourceState::query()->firstOrCreate(['game_id' => $game->id, 'source' => $source]);
        $failures = min(100, ((int) $state->consecutive_failures) + 1);
        $backoff = (array) config('gpa.price_refresh_backoff_minutes', [1, 5, 15, 30, 60]);
        $minutes = (int) ($backoff[min($failures - 1, count($backoff) - 1)] ?? 60);
        $state->forceFill([
            'last_attempt_at' => now(),
            'next_refresh_at' => now()->addMinutes(max(1, $minutes)),
            'status' => GameSourceState::STATUS_FAILED,
            'last_error' => mb_substr($error->getMessage(), 0, 500),
            'consecutive_failures' => $failures,
        ])->save();
    }

    private function nextSuccessfulRefresh(Game $game, string $source): CarbonInterface
    {
        if ($source === GameSourceState::SOURCE_STEAM && $game->release_status === Game::RELEASE_STATUS_ANNOUNCED) {
            return now()->addHours(max(1, (int) config('gpa.announced_steam_refresh_hours', 24)));
        }

        return now()->addHours(max(1, (int) config('gpa.price_refresh_interval_hours', 3)));
    }
}
