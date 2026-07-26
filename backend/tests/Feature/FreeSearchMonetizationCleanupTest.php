<?php

namespace Tests\Feature;

use App\Models\CurrentGamePrice;
use App\Models\Favorite;
use App\Models\Game;
use App\Models\GameSourceState;
use App\Models\SearchHistory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FreeSearchMonetizationCleanupTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_and_authenticated_searches_ignore_legacy_daily_limits(): void
    {
        $game = $this->createStoredGame(810001, 'Free Search');
        config()->set('gpa.guest_searches_per_day', 1);
        config()->set('gpa.free_searches_per_day', 1);
        config()->set('gpa.pro_searches_per_day', 1);

        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.11']);
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $response = $this->getJson("/api/prices?q=Free%20Search&appid={$game->steam_appid}")
                ->assertOk();
            $this->assertArrayNotHasKey('quota', $response->json());
        }

        Sanctum::actingAs(User::factory()->create());
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $response = $this->getJson("/api/prices?q=Free%20Search&appid={$game->steam_appid}")
                ->assertOk();
            $this->assertArrayNotHasKey('quota', $response->json());
        }

        $this->assertFalse(Schema::hasTable('daily_search_quotas'));
    }

    public function test_removed_quota_billing_and_admin_plan_endpoints_return_not_found(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/quota')->assertNotFound();
        $this->getJson('/api/plans')->assertNotFound();
        $this->postJson('/api/billing/request', ['plan_id' => 'pro_month'])->assertNotFound();
        $this->postJson('/api/billing/promo', ['code' => 'KEYSIGNAL-PRO'])->assertNotFound();
        $this->postJson('/api/admin/users/1/plan', ['plan' => 'pro'])->assertNotFound();
    }

    public function test_public_user_contract_has_no_subscription_fields(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $user = $this->getJson('/api/auth/me')->assertOk()->json();

        $this->assertArrayNotHasKey('plan', $user);
        $this->assertArrayNotHasKey('plan_label', $user);
        $this->assertArrayNotHasKey('plan_expires_at', $user);
    }

    public function test_ads_are_limited_to_post_results_and_footer_and_do_not_change_prices(): void
    {
        config()->set('gpa.ads_enabled', true);
        $slots = $this->getJson('/api/ads/config')
            ->assertOk()
            ->assertJsonCount(2, 'slots')
            ->json();

        $this->assertSame(['after_results', 'footer'], array_column($slots['slots'], 'placement'));
        $this->assertArrayNotHasKey('hidden_for_pro', $slots);

        $game = $this->createStoredGame(810002, 'Neutral Results');
        config()->set('gpa.digiseller_partner_id', 'first-partner');
        $first = $this->getJson("/api/prices?q=Neutral%20Results&appid={$game->steam_appid}")
            ->assertOk()
            ->json();

        config()->set('gpa.ads_enabled', false);
        config()->set('gpa.digiseller_partner_id', 'second-partner');
        $second = $this->getJson("/api/prices?q=Neutral%20Results&appid={$game->steam_appid}")
            ->assertOk()
            ->json();

        $this->assertSame($first['steam'], $second['steam']);
        $this->assertSame($first['plati'], $second['plati']);
        $this->assertSame($first['ggsel'], $second['ggsel']);
        $this->getJson('/api/ads/config')->assertOk()->assertJsonCount(0, 'slots');
    }

    public function test_upgrade_migration_preserves_account_favorites_and_history(): void
    {
        $user = User::factory()->create(['email' => 'legacy@example.com']);
        Favorite::query()->create([
            'user_id' => $user->id,
            'appid' => 810003,
            'game_name' => 'Legacy Favorite',
        ]);
        SearchHistory::query()->create([
            'user_id' => $user->id,
            'query' => 'Legacy Query',
            'appid' => 810003,
            'game_name' => 'Legacy Favorite',
        ]);

        $migration = require database_path('migrations/2026_07_25_194100_remove_pro_and_search_quotas.php');
        $migration->down();
        DB::table('users')->where('id', $user->id)->update([
            'plan' => 'pro',
            'plan_expires_at' => now()->addMonth(),
        ]);
        DB::table('daily_search_quotas')->insert([
            'quota_key' => 'user:'.$user->id,
            'day' => now()->utc()->format('Y-m-d'),
            'count' => 99,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $migration->up();

        $this->assertFalse(Schema::hasTable('daily_search_quotas'));
        $this->assertFalse(Schema::hasColumn('users', 'plan'));
        $this->assertFalse(Schema::hasColumn('users', 'plan_expires_at'));

        $login = $this->postJson('/api/auth/login', [
            'email' => 'legacy@example.com',
            'password' => 'password',
        ])->assertOk();

        $this->withToken($login->json('access_token'))
            ->getJson('/api/me/favorites')
            ->assertOk()
            ->assertJsonPath('items.0.game_name', 'Legacy Favorite');
        $this->getJson('/api/me/history')
            ->assertOk()
            ->assertJsonPath('items.0.query', 'Legacy Query');
    }

    public function test_technical_prices_throttle_remains_enabled(): void
    {
        $game = $this->createStoredGame(810004, 'Throttle Check');
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.204']);

        for ($attempt = 0; $attempt < 20; $attempt++) {
            $this->getJson("/api/prices?q=Throttle%20Check&appid={$game->steam_appid}")
                ->assertOk();
        }

        $this->getJson("/api/prices?q=Throttle%20Check&appid={$game->steam_appid}")
            ->assertTooManyRequests();
    }

    private function createStoredGame(int $appid, string $name): Game
    {
        $game = Game::query()->create([
            'steam_appid' => $appid,
            'name' => $name,
            'release_status' => Game::RELEASE_STATUS_RELEASED,
        ]);
        CurrentGamePrice::query()->create([
            'game_id' => $game->id,
            'source' => 'steam',
            'offer_kind' => 'official',
            'min_price_rub' => 999,
            'offer_count' => 1,
            'observed_at' => now(),
        ]);
        CurrentGamePrice::query()->create([
            'game_id' => $game->id,
            'source' => 'plati',
            'offer_kind' => 'key',
            'min_price_rub' => 499,
            'avg_price_rub' => 599,
            'offer_count' => 2,
            'cheapest_offer_title' => $name.' key',
            'cheapest_offer_url' => 'https://example.test/offer',
            'popular_offer_title' => $name.' popular',
            'popular_offer_url' => 'https://example.test/popular',
            'popular_offer_price_rub' => 549,
            'popular_offer_sales' => 10,
            'observed_at' => now(),
        ]);
        GameSourceState::query()->create([
            'game_id' => $game->id,
            'source' => 'steam',
            'status' => GameSourceState::STATUS_FRESH,
            'last_success_at' => now(),
        ]);

        return $game;
    }
}
