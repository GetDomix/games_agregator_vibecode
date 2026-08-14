<?php

namespace Tests\Feature;

use App\Models\CurrentGamePrice;
use App\Models\Game;
use App\Models\GamePriceObservation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GamePriceHistoryApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_history_returns_decision_context_without_account_change_log(): void
    {
        $game = $this->gameWithHistory();

        $response = $this->getJson("/api/games/{$game->steam_appid}/price-history?days=90");

        $response->assertOk()
            ->assertJsonPath('period_days', 90)
            ->assertJsonPath('current.price_rub', 1249)
            ->assertJsonPath('current.source', 'steam')
            ->assertJsonPath('statistics.minimum_price_rub', 1249)
            ->assertJsonPath('statistics.median_price_rub', 1500)
            ->assertJsonPath('verdict', 'low')
            ->assertJsonPath('coverage.observations', 7)
            ->assertJsonMissingPath('changes');
    }

    public function test_authenticated_history_lists_only_real_official_and_key_price_changes(): void
    {
        $game = $this->gameWithHistory();
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')
            ->getJson("/api/me/games/{$game->steam_appid}/price-history?days=90");

        $response->assertOk()
            ->assertJsonCount(3, 'changes')
            ->assertJsonPath('changes.0.source', 'plati')
            ->assertJsonPath('changes.0.previous_price_rub', 1500)
            ->assertJsonPath('changes.0.price_rub', 1299)
            ->assertJsonPath('changes.0.change_percent', -13)
            ->assertJsonPath('changes.1.source', 'steam')
            ->assertJsonPath('changes.1.previous_price_rub', 1799)
            ->assertJsonPath('changes.1.price_rub', 1249)
            ->assertJsonPath('changes.1.change_percent', -31)
            ->assertJsonPath('changes.2.source', 'steam')
            ->assertJsonPath('changes.2.previous_price_rub', 2000)
            ->assertJsonPath('changes.2.price_rub', 1799);

        $this->assertFalse(collect($response->json('changes'))->contains(
            fn (array $change) => $change['offer_kind'] === 'account' || $change['previous_price_rub'] === $change['price_rub']
        ));
    }

    public function test_change_log_requires_an_account(): void
    {
        $game = $this->gameWithHistory();

        $this->getJson("/api/me/games/{$game->steam_appid}/price-history?days=90")
            ->assertUnauthorized();
    }

    public function test_many_checks_from_one_day_do_not_produce_a_premature_verdict(): void
    {
        $game = Game::query()->create([
            'steam_appid' => 292030,
            'name' => 'The Witcher 3: Wild Hunt',
            'release_status' => Game::RELEASE_STATUS_RELEASED,
        ]);
        CurrentGamePrice::query()->create([
            'game_id' => $game->id,
            'source' => 'steam',
            'offer_kind' => 'official',
            'min_price_rub' => 299,
            'observed_at' => now(),
        ]);
        foreach ([499, 449, 399, 349, 329, 299] as $minutes => $price) {
            GamePriceObservation::query()->create([
                'game_id' => $game->id,
                'source' => 'steam',
                'offer_kind' => 'official',
                'min_price_rub' => $price,
                'observed_at' => now()->subMinutes($minutes + 1),
            ]);
        }

        $this->getJson("/api/games/{$game->steam_appid}/price-history?days=90")
            ->assertOk()
            ->assertJsonPath('coverage.checks', 6)
            ->assertJsonPath('coverage.observed_days', 1)
            ->assertJsonPath('coverage.sufficient', false)
            ->assertJsonPath('verdict', 'insufficient');
    }

    private function gameWithHistory(): Game
    {
        $game = Game::query()->create([
            'steam_appid' => 1091500,
            'name' => 'Cyberpunk 2077',
            'release_status' => Game::RELEASE_STATUS_RELEASED,
        ]);

        foreach ([
            ['steam', 'official', 1249],
            ['plati', 'key', 1299],
            ['ggsel', 'key', 1400],
            ['plati', 'account', 100],
        ] as [$source, $kind, $price]) {
            CurrentGamePrice::query()->create([
                'game_id' => $game->id,
                'source' => $source,
                'offer_kind' => $kind,
                'min_price_rub' => $price,
                'observed_at' => now(),
            ]);
        }

        foreach ([
            [-100, 'steam', 'official', 2000],
            [-80, 'steam', 'official', 1799],
            [-60, 'plati', 'key', 1500],
            [-30, 'steam', 'official', 1799],
            [-20, 'steam', 'official', 1249],
            [-10, 'plati', 'key', 1500],
            [-5, 'plati', 'key', 1299],
            [-1, 'ggsel', 'key', 1400],
            [-1, 'plati', 'account', 100],
        ] as [$days, $source, $kind, $price]) {
            GamePriceObservation::query()->create([
                'game_id' => $game->id,
                'source' => $source,
                'offer_kind' => $kind,
                'min_price_rub' => $price,
                'observed_at' => now()->addDays($days),
            ]);
        }

        return $game;
    }
}
