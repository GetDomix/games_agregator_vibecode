<?php

namespace Tests\Feature;

use App\Models\AlertDelivery;
use App\Models\AlertEvent;
use App\Models\Favorite;
use App\Models\FavoriteAlert;
use App\Models\Game;
use App\Models\GameSourceState;
use App\Models\User;
use App\Services\GgselService;
use App\Services\PlatiService;
use App\Services\SteamService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ReleaseReadinessOperationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_price_sources_use_faked_http_and_normalize_responses(): void
    {
        Http::fake([
            'https://store.steampowered.com/api/appdetails*' => Http::response([
                '730' => ['success' => true, 'data' => [
                    'name' => 'Counter-Strike 2',
                    'is_free' => false,
                    'price_overview' => ['final' => 12345],
                    'release_date' => ['coming_soon' => false, 'date' => 'Aug 21, 2012'],
                ]],
            ]),
            'https://plati.market/api/search.ashx*' => Http::response([
                'Totalpages' => 1,
                'items' => [['id' => 1, 'name' => 'Counter-Strike 2 Steam key', 'price_rur' => 500, 'numsold' => 10]],
            ]),
            'https://api.ggsel.com/elastic/goods/query' => Http::response([
                'data' => [['id_goods' => 2, 'name' => 'Counter-Strike 2 gift', 'price_wmr' => 450, 'cnt_sell' => 5]],
            ]),
        ]);

        $steam = app(SteamService::class)->refreshDetails(730, 'CS2');
        [$plati] = app(PlatiService::class)->search('Counter-Strike 2');
        [$ggsel] = app(GgselService::class)->search('Counter-Strike 2');

        $this->assertSame(123.45, $steam['price_rub']);
        $this->assertSame('released', $steam['release_status']);
        $this->assertSame(500.0, $plati[0]['price_rub']);
        $this->assertSame(450.0, $ggsel[0]['price_rub']);
        Http::assertSentCount(3);
        Http::assertSent(fn (Request $request) => $request->url() === 'https://api.ggsel.com/elastic/goods/query'
            && $request['search_term'] === 'Counter-Strike 2');
    }

    public function test_schedule_has_one_canonical_dispatch_owner_and_periodic_snapshot(): void
    {
        $this->assertSame(0, Artisan::call('schedule:list'));
        $schedule = Artisan::output();

        $this->assertSame(1, substr_count($schedule, 'prices:dispatch-due'));
        $this->assertSame(1, substr_count($schedule, 'ops:snapshot --hours=24'));
        $this->assertStringNotContainsString('radar:scan', $schedule);
    }

    public function test_operations_snapshot_reports_aggregate_refresh_queue_and_delivery_health(): void
    {
        $this->travelTo(now()->startOfHour());
        $user = User::factory()->create();
        $game = Game::query()->create(['steam_appid' => 900, 'name' => 'Observed', 'release_status' => 'released']);
        GameSourceState::query()->create([
            'game_id' => $game->id,
            'source' => 'steam',
            'status' => 'fresh',
            'last_attempt_at' => now()->subHour(),
            'last_success_at' => now()->subHour(),
        ]);
        GameSourceState::query()->create([
            'game_id' => $game->id,
            'source' => 'plati',
            'status' => 'failed',
            'last_attempt_at' => now()->subHour(),
            'last_error' => 'source unavailable',
        ]);
        $favorite = Favorite::query()->create(['user_id' => $user->id, 'game_id' => $game->id, 'appid' => 900, 'game_name' => 'Observed']);
        $alert = FavoriteAlert::query()->create(['favorite_id' => $favorite->id, 'condition_type' => 'target_price', 'target_value' => 100, 'status' => 'triggered']);
        $event = AlertEvent::query()->create([
            'favorite_alert_id' => $alert->id,
            'alert_cycle' => 0,
            'user_id' => $user->id,
            'favorite_id' => $favorite->id,
            'game_id' => $game->id,
            'source' => 'steam',
            'offer_kind' => 'official',
            'offer_price_rub' => 99,
            'observed_at' => now(),
        ]);
        AlertDelivery::query()->create(['alert_event_id' => $event->id, 'status' => 'sent', 'attempts' => 1, 'sent_at' => now()]);

        $this->assertSame(0, Artisan::call('ops:snapshot', ['--hours' => 24]));
        $snapshot = json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('pgsql', DB::getDriverName());
        $this->assertSame(1, $snapshot['refreshed_games']);
        $this->assertSame(1, $snapshot['source_states']['steam']['fresh']);
        $this->assertSame(1, $snapshot['source_states']['plati']['failed']);
        $this->assertSame(0, $snapshot['queue']['pending']);
        $this->assertSame(1, $snapshot['alert_events_created']);
        $this->assertSame(1, $snapshot['deliveries']['sent']);
    }

    public function test_operations_snapshot_rejects_an_unbounded_window(): void
    {
        $this->assertSame(1, Artisan::call('ops:snapshot', ['--hours' => 0]));
    }
}
