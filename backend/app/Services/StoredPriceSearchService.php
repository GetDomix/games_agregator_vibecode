<?php

namespace App\Services;

use App\Models\CurrentGamePrice;
use App\Models\Game;
use App\Models\GameSourceState;

class StoredPriceSearchService
{
    public function candidates(string $query, int $limit = 8): array
    {
        $term = trim($query);
        if ($term === '') {
            return [];
        }
        $escaped = str_replace(['%', '_'], ['\\%', '\\_'], mb_strtolower($term));
        $games = Game::query()
            ->with('currentPrices')
            ->whereRaw('LOWER(name) LIKE ?', ["%{$escaped}%"])
            ->limit($limit * 3)
            ->get();

        return $games->sortBy(fn (Game $game) => match (true) {
            mb_strtolower($game->name) === mb_strtolower($term) => 0,
            str_starts_with(mb_strtolower($game->name), mb_strtolower($term)) => 1,
            default => 2,
        })->take($limit)->map(function (Game $game) {
            $steam = $game->currentPrices->first(fn (CurrentGamePrice $p) => $p->source === 'steam' && $p->offer_kind === 'official');
            return [
                'appid' => (int) $game->steam_appid, 'name' => $game->name,
                'tiny_image' => $game->header_image, 'header_image' => $game->header_image,
                'price_rub' => $steam?->min_price_rub !== null ? (float) $steam->min_price_rub : null,
            ];
        })->values()->all();
    }

    public function result(Game $game, string $query): array
    {
        $game->loadMissing(['currentPrices', 'sourceStates', 'steamRegionalPrices']);
        $stateBySource = $game->sourceStates->keyBy('source');
        $steamPrice = $game->currentPrices->first(fn (CurrentGamePrice $p) => $p->source === 'steam' && $p->offer_kind === 'official');
        $steam = [
            'appid' => (int) $game->steam_appid, 'name' => $game->name,
            'header_image' => $game->header_image, 'store_url' => "https://store.steampowered.com/app/{$game->steam_appid}/",
            'price_rub' => $steamPrice?->min_price_rub, 'price_initial_rub' => $steamPrice?->price_initial_rub !== null ? (float) $steamPrice->price_initial_rub : null,
            'regional_prices' => $game->steamRegionalPrices->map(fn ($regional) => [
                'region' => $regional->region,
                'label' => $this->regionalLabel($regional->region),
                'currency' => $regional->currency,
                'amount' => $regional->price_amount,
                'price_rub' => $regional->price_rub,
                'observed_at' => $regional->observed_at?->toIso8601String(),
            ])->values(),
            'discount_percent' => $steamPrice?->discount_percent !== null ? (int) $steamPrice->discount_percent : 0, 'is_free' => $steamPrice?->min_price_rub === '0.00',
            'available_in_ru' => $steamPrice !== null, 'note' => $game->release_status === Game::RELEASE_STATUS_ANNOUNCED ? 'Игра ещё не вышла: предложения маркетплейсов не запрашиваются.' : null,
        ];
        $plati = $this->market('plati', 'Plati.Market', $game, $stateBySource->get('plati'));
        $ggsel = $this->market('ggsel', 'GGsel', $game, $stateBySource->get('ggsel'));
        if ($game->release_status === Game::RELEASE_STATUS_ANNOUNCED) {
            $plati = $this->emptyMarket('plati', 'Plati.Market');
            $ggsel = $this->emptyMarket('ggsel', 'GGsel');
        }
        $warnings = $this->warnings($stateBySource);
        $steamValue = $steamPrice?->min_price_rub !== null ? (float) $steamPrice->min_price_rub : null;

        return [
            'query' => $query, 'steam' => $steam, 'candidates' => [], 'plati' => $plati, 'ggsel' => $ggsel,
            'warnings' => $warnings, 'saved_to_history' => false, 'is_favorite' => false,
            'deal' => DealScoreService::compute($steamValue, $plati, $ggsel),
            'refreshing' => $stateBySource->contains(fn ($state) => $state->status === GameSourceState::STATUS_PENDING),
            'freshness' => $stateBySource->map(fn ($state) => ['source' => $state->source, 'status' => $state->status, 'last_success_at' => $state->last_success_at?->toIso8601String(), 'next_refresh_at' => $state->next_refresh_at?->toIso8601String()])->values(),
        ];
    }

    private function market(string $source, string $label, Game $game, ?GameSourceState $state): array
    {
        $prices = $game->currentPrices->where('source', $source);
        $groups = $prices->map(fn (CurrentGamePrice $p) => [
            'kind' => $p->offer_kind, 'label' => Classifier::label($p->offer_kind), 'count' => $p->offer_count,
            'min_price' => $p->min_price_rub, 'avg_price' => $p->avg_price_rub,
            'currency_prices' => $p->currency_prices,
            'cheapest' => ['title' => $p->cheapest_offer_title, 'url' => $p->cheapest_offer_url, 'price_rub' => $p->min_price_rub],
            'popular' => ['title' => $p->popular_offer_title, 'url' => $p->popular_offer_url, 'price_rub' => $p->popular_offer_price_rub, 'sales' => $p->popular_offer_sales],
        ])->values()->all();

        return ['marketplace' => $source, 'label' => $label, 'total_offers' => array_sum(array_column($groups, 'count')), 'scanned_offers' => array_sum(array_column($groups, 'count')), 'by_kind' => $groups, 'error' => $state?->status === 'failed' ? 'Источник временно недоступен' : null];
    }

    private function emptyMarket(string $source, string $label): array
    {
        return ['marketplace' => $source, 'label' => $label, 'total_offers' => 0, 'scanned_offers' => 0, 'by_kind' => [], 'error' => null];
    }

    private function regionalLabel(string $region): string
    {
        foreach ((array) config('gpa.steam_price_regions', []) as $configured) {
            if (($configured['region'] ?? null) === $region) {
                return (string) $configured['label'];
            }
        }

        return $region;
    }

    private function warnings($states): array
    {
        return $states->filter(fn ($s) => $s->status === 'failed')->map(fn ($s) => ucfirst($s->source).': источник временно недоступен; показана последняя успешная цена.')->values()->all();
    }
}
