<?php

namespace Tests\Feature;

use App\Jobs\RefreshGameSourceJob;
use App\Models\AdminAuditLog;
use App\Models\Game;
use App\Models\GameSourceState;
use App\Models\SearchHistory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminOperationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_overview_reports_operational_health_without_user_details(): void
    {
        $admin = User::factory()->create(['admin_role' => User::ROLE_ADMIN]);
        $game = Game::query()->create(['steam_appid' => 730, 'name' => 'Counter-Strike 2', 'release_status' => 'released']);
        GameSourceState::query()->create([
            'game_id' => $game->id,
            'source' => 'steam',
            'status' => 'failed',
            'last_attempt_at' => now(),
            'last_error' => 'temporary error',
            'consecutive_failures' => 2,
        ]);
        SearchHistory::query()->create(['user_id' => $admin->id, 'query' => 'missing game']);
        DB::table('jobs')->insert([
            'queue' => 'default', 'payload' => '{}', 'attempts' => 0,
            'reserved_at' => null, 'available_at' => now()->timestamp, 'created_at' => now()->timestamp,
        ]);

        Sanctum::actingAs($admin);
        $this->getJson('/api/admin/overview')
            ->assertOk()
            ->assertJsonPath('operations.queue.pending', 1)
            ->assertJsonPath('operations.sources.0.source', 'steam')
            ->assertJsonPath('operations.sources.0.counts.failed', 1)
            ->assertJsonPath('recent_source_failures.0.appid', 730)
            ->assertJsonPath('problem_searches.0.query', 'missing game')
            ->assertJsonMissingPath('recent_users');
    }

    public function test_non_admin_cannot_use_admin_endpoints(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/admin/overview')->assertForbidden();
        $this->getJson('/api/admin/users')->assertForbidden();
        $this->postJson('/api/admin/games/730/refresh')->assertForbidden();
    }

    public function test_admin_can_search_users_without_private_details(): void
    {
        $admin = User::factory()->create(['admin_role' => User::ROLE_ADMIN]);
        $target = User::factory()->create(['email' => 'player@example.com', 'display_name' => 'Player']);
        Sanctum::actingAs($admin);

        $this->getJson('/api/admin/users?q=player')
            ->assertOk()
            ->assertJsonCount(1, 'items')
            ->assertJsonPath('items.0.id', $target->id)
            ->assertJsonStructure(['items' => [['favorites_count', 'searches_count', 'telegram_linked']]])
            ->assertJsonMissing(['password', 'remember_token']);
    }

    public function test_admin_can_queue_a_scoped_game_refresh_with_audit_log(): void
    {
        Queue::fake();
        $admin = User::factory()->create(['admin_role' => User::ROLE_ADMIN]);
        Game::query()->create(['steam_appid' => 1091500, 'name' => 'Cyberpunk 2077', 'release_status' => 'released']);
        Sanctum::actingAs($admin);

        $this->postJson('/api/admin/games/1091500/refresh', ['sources' => ['steam', 'ggsel']])
            ->assertStatus(202)
            ->assertJsonPath('sources.0', 'steam')
            ->assertJsonPath('sources.1', 'ggsel');

        Queue::assertPushed(RefreshGameSourceJob::class, 2);
        $audit = AdminAuditLog::query()->where('action', 'game.refresh_requested')->sole();
        $this->assertSame(['steam', 'ggsel'], $audit->context['sources']);
    }
}
