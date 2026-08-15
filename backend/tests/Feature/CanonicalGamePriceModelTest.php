<?php

namespace Tests\Feature;

use App\Models\CurrentGamePrice;
use App\Models\Favorite;
use App\Models\Game;
use App\Models\GamePriceObservation;
use App\Models\GameSourceState;
use App\Models\PriceSnapshot;
use App\Models\SearchHistory;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CanonicalGamePriceModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_canonical_tables_and_shared_relations_are_available(): void
    {
        foreach (['games', 'game_source_states', 'current_game_prices', 'game_price_observations'] as $table) {
            $this->assertTrue(Schema::hasTable($table));
        }
        $this->assertTrue(Schema::hasColumn('favorites', 'game_id'));

        $game = Game::query()->create([
            'steam_appid' => 570,
            'name' => 'Dota 2',
            'release_status' => Game::RELEASE_STATUS_RELEASED,
            'release_date' => '2013-07-09',
        ]);
        $firstUser = User::factory()->create();
        $secondUser = User::factory()->create();

        foreach ([$firstUser, $secondUser] as $user) {
            Favorite::query()->create([
                'user_id' => $user->id,
                'game_id' => $game->id,
                'appid' => 570,
                'game_name' => 'Dota 2',
            ]);
        }

        CurrentGamePrice::query()->create([
            'game_id' => $game->id,
            'source' => GameSourceState::SOURCE_STEAM,
            'offer_kind' => CurrentGamePrice::OFFER_KIND_OFFICIAL,
            'min_price_rub' => 0,
            'offer_count' => 1,
            'observed_at' => now(),
        ]);

        $this->assertCount(2, $game->fresh()->favorites);
        $this->assertSame(
            $firstUser->favorites()->firstOrFail()->game_id,
            $secondUser->favorites()->firstOrFail()->game_id
        );
        $this->assertSame('0.00', $game->fresh()->currentPrices()->firstOrFail()->min_price_rub);
        $this->assertTrue($game->isReleased());
    }

    public function test_database_rejects_duplicate_games(): void
    {
        Game::query()->create([
            'steam_appid' => 730,
            'name' => 'Counter-Strike 2',
        ]);

        $this->expectException(QueryException::class);

        Game::query()->create([
            'steam_appid' => 730,
            'name' => 'Duplicate',
        ]);
    }

    public function test_current_price_is_unique_per_game_source_and_offer_kind(): void
    {
        $game = Game::query()->create([
            'steam_appid' => 440,
            'name' => 'Team Fortress 2',
        ]);

        CurrentGamePrice::query()->create([
            'game_id' => $game->id,
            'source' => 'steam',
            'offer_kind' => 'official',
            'min_price_rub' => 10,
            'observed_at' => now(),
        ]);

        $this->expectException(QueryException::class);

        CurrentGamePrice::query()->create([
            'game_id' => $game->id,
            'source' => 'steam',
            'offer_kind' => 'official',
            'min_price_rub' => 20,
            'observed_at' => now(),
        ]);
    }

    public function test_postgresql_rejects_invalid_source(): void
    {
        $this->assertSame('pgsql', DB::getDriverName());

        $game = Game::query()->create([
            'steam_appid' => 550,
            'name' => 'Left 4 Dead 2',
        ]);

        $this->expectException(QueryException::class);

        CurrentGamePrice::query()->create([
            'game_id' => $game->id,
            'source' => 'untrusted-shop',
            'offer_kind' => 'key',
            'min_price_rub' => 10,
            'observed_at' => now(),
        ]);
    }

    public function test_postgresql_rejects_invalid_offer_kind(): void
    {
        $this->assertSame('pgsql', DB::getDriverName());

        $game = Game::query()->create([
            'steam_appid' => 620,
            'name' => 'Portal 2',
        ]);

        $this->expectException(QueryException::class);

        CurrentGamePrice::query()->create([
            'game_id' => $game->id,
            'source' => 'plati',
            'offer_kind' => 'mystery',
            'min_price_rub' => 10,
            'observed_at' => now(),
        ]);
    }

    public function test_migration_backfills_legacy_favorites_and_latest_steam_price(): void
    {
        $migration = $this->canonicalMigration();
        // This test temporarily rolls back the old canonical schema by itself.
        // Newer tables depend on games, so remove only those test-local dependents first.
        Schema::dropIfExists('alert_deliveries');
        Schema::dropIfExists('alert_events');
        Schema::dropIfExists('steam_regional_prices');
        Schema::table('favorite_alerts', fn (Blueprint $table) => $table->dropColumn('cycle'));
        $migration->down();

        $firstUser = User::factory()->create();
        $secondUser = User::factory()->create();

        SearchHistory::query()->create([
            'user_id' => $firstUser->id,
            'query' => 'portal',
            'appid' => 400,
            'game_name' => 'Old Portal Name',
            'steam_price_rub' => 299,
        ]);
        Favorite::query()->create([
            'user_id' => $firstUser->id,
            'appid' => 400,
            'game_name' => 'Portal',
            'header_image' => 'https://example.test/portal.jpg',
            'last_steam_price_rub' => 199,
        ]);
        Favorite::query()->create([
            'user_id' => $secondUser->id,
            'appid' => 400,
            'game_name' => 'Portal',
            'last_steam_price_rub' => 179,
        ]);
        $latestSnapshot = PriceSnapshot::query()->create([
            'user_id' => $secondUser->id,
            'appid' => 400,
            'steam_price_rub' => 129,
            'market_min_rub' => 79,
            'source_query' => 'portal',
        ]);
        $latestSnapshot->forceFill([
            'created_at' => now(),
            'updated_at' => now(),
        ])->saveQuietly();

        $olderSnapshotInsertedLater = PriceSnapshot::query()->create([
            'user_id' => $firstUser->id,
            'appid' => 400,
            'steam_price_rub' => 149,
            'market_min_rub' => 99,
            'source_query' => 'portal',
        ]);
        $olderSnapshotInsertedLater->forceFill([
            'created_at' => now()->subDay(),
            'updated_at' => now()->subDay(),
        ])->saveQuietly();

        $migration->up();

        $game = Game::query()->where('steam_appid', 400)->firstOrFail();
        $favorites = Favorite::query()->where('appid', 400)->get();

        $this->assertSame('Portal', $game->name);
        $this->assertSame('unknown', $game->release_status);
        $this->assertCount(2, $favorites);
        $this->assertTrue($favorites->every(fn (Favorite $favorite) => $favorite->game_id === $game->id));
        $this->assertSame(1, Game::query()->where('steam_appid', 400)->count());

        $price = CurrentGamePrice::query()->where('game_id', $game->id)->firstOrFail();
        $this->assertSame('steam', $price->source);
        $this->assertSame('official', $price->offer_kind);
        $this->assertSame('129.00', $price->min_price_rub);

        $this->assertDatabaseCount('game_price_observations', 1);
        $this->assertDatabaseHas('game_source_states', [
            'game_id' => $game->id,
            'source' => 'steam',
            'status' => 'stale',
        ]);
        $this->assertDatabaseMissing('current_game_prices', ['min_price_rub' => 79]);
        $this->assertDatabaseMissing('current_game_prices', ['min_price_rub' => 99]);
    }

    private function canonicalMigration(): object
    {
        return require database_path('migrations/2026_07_25_140600_create_canonical_game_price_model.php');
    }
}
