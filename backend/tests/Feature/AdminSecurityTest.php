<?php

namespace Tests\Feature;

use App\Jobs\RefreshGameSourceJob;
use App\Models\AdminAuditLog;
use App\Models\ExternalIdentity;
use App\Models\Favorite;
use App\Models\Game;
use App\Models\GameSourceState;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AdminSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_search_treats_sql_and_wildcard_payloads_as_literal_text(): void
    {
        $owner = User::factory()->create(['admin_role' => User::ROLE_OWNER]);
        User::factory()->create(['email' => 'percent@example.com', 'display_name' => '100% Player']);
        User::factory()->create(['email' => 'underscore@example.com', 'display_name' => 'Under_score']);
        User::factory()->create(['email' => 'backslash@example.com', 'display_name' => 'Back\\slash']);
        User::factory()->create(['email' => 'near-match@example.com', 'display_name' => 'UnderXscore Backslash']);
        User::factory()->create(['email' => 'ordinary@example.com', 'display_name' => 'Ordinary']);
        Sanctum::actingAs($owner);

        $this->getJson('/api/admin/users?q='.urlencode("%' OR 1=1 --"))
            ->assertOk()
            ->assertJsonCount(0, 'data');
        $this->getJson('/api/admin/users?q='.urlencode('100%'))
            ->assertOk()
            ->assertJsonCount(1, 'data');
        $this->getJson('/api/admin/users?q='.urlencode('under_'))
            ->assertOk()
            ->assertJsonCount(1, 'data');
        $this->getJson('/api/admin/users?q='.urlencode('back\\slash'))
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_numeric_user_search_matches_the_exact_id(): void
    {
        $owner = User::factory()->create(['admin_role' => User::ROLE_OWNER]);
        $target = User::factory()->create([
            'email' => 'target@example.com',
            'display_name' => 'Target',
        ]);
        User::factory()->create([
            'email' => $target->id.'suffix@example.com',
            'display_name' => 'Other',
        ]);
        Sanctum::actingAs($owner);

        $this->getJson('/api/admin/users?q='.$target->id)
            ->assertOk()
            ->assertJsonFragment(['id' => $target->id, 'email' => 'target@example.com']);
    }

    public function test_admin_user_and_audit_payloads_never_expose_secrets(): void
    {
        $owner = User::factory()->create([
            'admin_role' => User::ROLE_OWNER,
            'telegram_chat_id' => '123456',
            'remember_token' => 'remember-secret',
        ]);
        $owner->tokens()->create(['name' => 'admin-token', 'token' => hash('sha256', 'plain-secret')]);
        AdminAuditLog::query()->create([
            'actor_id' => $owner->id,
            'action' => 'game.refresh_requested',
            'target_type' => 'game',
            'target_id' => '730',
            'request_id' => Str::uuid(),
        ]);
        Sanctum::actingAs($owner);

        foreach (['/api/admin/users', '/api/admin/audit'] as $uri) {
            $json = $this->getJson($uri)->assertOk()->getContent();
            $this->assertStringNotContainsString('password', $json);
            $this->assertStringNotContainsString('remember_token', $json);
            $this->assertStringNotContainsString('telegram_chat_id', $json);
            $this->assertStringNotContainsString('personal_access_tokens', $json);
            $this->assertStringNotContainsString('remember-secret', $json);
            $this->assertStringNotContainsString('plain-secret', $json);
        }
    }

    public function test_admin_cannot_see_role_change_audit_but_owner_can(): void
    {
        $owner = User::factory()->create(['admin_role' => User::ROLE_OWNER]);
        $admin = User::factory()->create(['admin_role' => User::ROLE_ADMIN]);
        $targetId = 'security-test-'.Str::uuid();
        AdminAuditLog::query()->create([
            'actor_id' => $owner->id,
            'action' => 'admin.role_changed',
            'target_type' => 'user',
            'target_id' => $targetId,
            'request_id' => Str::uuid(),
        ]);

        Sanctum::actingAs($admin);
        $adminLogs = $this->getJson('/api/admin/audit')->assertOk()->json('data');
        $this->assertNotContains('admin.role_changed', array_column($adminLogs, 'action'));

        Sanctum::actingAs($owner);
        $this->getJson('/api/admin/audit')
            ->assertOk()
            ->assertJsonFragment([
                'action' => 'admin.role_changed',
                'target_id' => $targetId,
            ]);
    }

    public function test_admin_overview_hides_role_change_audit_but_owner_overview_includes_it(): void
    {
        $owner = User::factory()->create(['admin_role' => User::ROLE_OWNER]);
        $admin = User::factory()->create(['admin_role' => User::ROLE_ADMIN]);
        $targetId = 'overview-security-test-'.Str::uuid();
        AdminAuditLog::query()->create([
            'actor_id' => $owner->id,
            'action' => 'admin.role.changed',
            'target_type' => 'user',
            'target_id' => 'unrelated-action',
            'request_id' => Str::uuid(),
        ]);
        AdminAuditLog::query()->create([
            'actor_id' => $owner->id,
            'action' => 'admin.role_changed',
            'target_type' => 'user',
            'target_id' => $targetId,
            'request_id' => Str::uuid(),
        ]);

        Sanctum::actingAs($admin);
        $this->getJson('/api/admin/overview')
            ->assertOk()
            ->assertJsonMissing(['target_id' => $targetId])
            ->assertJsonFragment(['target_id' => 'unrelated-action']);

        Sanctum::actingAs($owner);
        $this->getJson('/api/admin/overview')
            ->assertOk()
            ->assertJsonFragment([
                'action' => 'admin.role_changed',
                'target_id' => $targetId,
            ]);
    }

    public function test_audit_pagination_is_capped_at_fifty(): void
    {
        Sanctum::actingAs(User::factory()->create(['admin_role' => User::ROLE_OWNER]));

        $this->getJson('/api/admin/audit?per_page=51')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('per_page');
    }

    public function test_audit_endpoint_preserves_admin_authentication_boundaries(): void
    {
        $this->getJson('/api/admin/audit')->assertUnauthorized();

        Sanctum::actingAs(User::factory()->create(['admin_role' => User::ROLE_USER]));
        $this->getJson('/api/admin/audit')->assertForbidden();
    }

    #[DataProvider('malformedRefreshPayloads')]
    public function test_refresh_endpoint_rejects_malformed_or_extra_fields(array $payload): void
    {
        Queue::fake();
        $admin = User::factory()->create(['admin_role' => User::ROLE_ADMIN]);
        Game::query()->create([
            'steam_appid' => 730,
            'name' => 'Counter-Strike 2',
            'release_status' => 'released',
        ]);
        $auditCount = (int) AdminAuditLog::query()->count();
        Sanctum::actingAs($admin);

        $this->postJson('/api/admin/games/730/refresh', $payload)
            ->assertUnprocessable();

        Queue::assertNothingPushed();
        $this->assertDatabaseCount('admin_audit_logs', $auditCount);
    }

    public static function malformedRefreshPayloads(): array
    {
        return [
            'mass assignment' => [['sources' => ['steam'], 'admin_role' => User::ROLE_OWNER]],
            'unknown field' => [['role' => User::ROLE_OWNER]],
            'nested source' => [['sources' => [['steam']]]],
            'unknown source' => [['sources' => ['internal']]],
            'object instead of list' => [['sources' => ['source' => 'steam']]],
        ];
    }

    #[DataProvider('adminWriteMethodVariants')]
    public function test_admin_write_routes_reject_method_variants(
        string $method,
        string $uriTemplate,
        array $payload,
    ): void {
        Queue::fake();
        $owner = User::factory()->create(['admin_role' => User::ROLE_OWNER]);
        $target = User::factory()->create();
        Game::query()->create([
            'steam_appid' => 730,
            'name' => 'Counter-Strike 2',
            'release_status' => 'released',
        ]);
        $auditCount = (int) AdminAuditLog::query()->count();
        Sanctum::actingAs($owner);
        $uri = str_replace('{user}', (string) $target->id, $uriTemplate);

        $this->json($method, $uri, $payload)
            ->assertStatus(405)
            ->assertHeader('Allow');

        $this->assertSame(User::ROLE_USER, $target->fresh()->admin_role);
        $this->assertDatabaseCount('admin_audit_logs', $auditCount);
        Queue::assertNothingPushed();
    }

    public static function adminWriteMethodVariants(): array
    {
        return [
            'team POST' => ['POST', '/api/admin/team/{user}', ['role' => User::ROLE_ADMIN]],
            'team PUT' => ['PUT', '/api/admin/team/{user}', ['role' => User::ROLE_ADMIN]],
            'team DELETE' => ['DELETE', '/api/admin/team/{user}', []],
            'refresh PATCH' => ['PATCH', '/api/admin/games/730/refresh', ['sources' => ['steam']]],
            'refresh PUT' => ['PUT', '/api/admin/games/730/refresh', ['sources' => ['steam']]],
            'refresh DELETE' => ['DELETE', '/api/admin/games/730/refresh', []],
        ];
    }

    public function test_admin_internal_errors_are_sanitized_even_when_debug_is_enabled(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Requires a PostgreSQL CHECK constraint for fault injection.');
        }

        Queue::fake();
        config([
            'app.debug' => true,
            'database.connections.pgsql.password' => 'database-password-sentinel',
            'gpa.admin_emails' => 'hidden-owner-list@example.com',
        ]);
        $owner = User::factory()->create(['admin_role' => User::ROLE_OWNER]);
        $target = User::factory()->create([
            'email' => 'private-target@example.com',
            'telegram_chat_id' => 'telegram-private-sentinel',
        ]);
        $token = $target->createToken('rollback-proof');
        $auditCount = (int) AdminAuditLog::query()->count();
        Sanctum::actingAs($owner);
        $constraint = 'task6_reject_role_audit_http_'.str_replace('-', '', (string) Str::uuid());
        DB::statement("ALTER TABLE admin_audit_logs ADD CONSTRAINT {$constraint} CHECK (action <> 'admin.role_changed') NOT VALID");

        try {
            $response = $this->patchJson("/api/admin/team/{$target->id}", [
                'role' => User::ROLE_ADMIN,
                'current_password' => 'request-password-sentinel',
            ]);
        } finally {
            DB::statement("ALTER TABLE admin_audit_logs DROP CONSTRAINT IF EXISTS {$constraint}");
        }

        $response->assertStatus(500)->assertExactJson(['message' => 'Внутренняя ошибка сервера']);
        foreach ([
            'trace',
            'exception',
            'SQLSTATE',
            'insert into',
            'database-password-sentinel',
            'hidden-owner-list@example.com',
            'private-target@example.com',
            'telegram-private-sentinel',
            'request-password-sentinel',
        ] as $forbidden) {
            $this->assertStringNotContainsStringIgnoringCase($forbidden, $response->getContent());
        }
        $this->assertSame(User::ROLE_USER, $target->fresh()->admin_role);
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $token->accessToken->id]);
        $this->assertDatabaseCount('admin_audit_logs', $auditCount);
        Queue::assertNotPushed(RefreshGameSourceJob::class);
    }

    public function test_browser_favorites_hide_historical_source_exception_messages(): void
    {
        [$user] = $this->favoriteWithHistoricalSourceError('browser-history-secret-sentinel');
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/me/favorites')
            ->assertOk()
            ->assertJsonPath('items.0.freshness.0.last_error', 'source_refresh_failed');

        $this->assertStringNotContainsString('browser-history-secret-sentinel', $response->getContent());
    }

    public function test_internal_telegram_favorites_hide_historical_source_exception_messages(): void
    {
        config(['gpa.radar_service_token' => 'task6-radar-token']);
        [$user] = $this->favoriteWithHistoricalSourceError('bot-history-secret-sentinel');
        $user->forceFill(['telegram_chat_id' => '101'])->save();
        ExternalIdentity::query()->create([
            'user_id' => $user->id,
            'provider' => 'telegram',
            'provider_subject' => '101',
        ]);

        $response = $this->getJson('/api/internal/telegram/favorites?telegram_user_id=101', [
            'X-Radar-Token' => 'task6-radar-token',
        ])->assertOk()->assertJsonPath('items.0.freshness.0.last_error', 'source_refresh_failed');

        $this->assertStringNotContainsString('bot-history-secret-sentinel', $response->getContent());
    }

    /** @return array{User, Favorite} */
    private function favoriteWithHistoricalSourceError(string $sentinel): array
    {
        $user = User::factory()->create();
        $game = Game::query()->create([
            'steam_appid' => 987654,
            'name' => 'Historical Error Game',
            'release_status' => 'released',
        ]);
        GameSourceState::query()->create([
            'game_id' => $game->id,
            'source' => GameSourceState::SOURCE_STEAM,
            'status' => GameSourceState::STATUS_FAILED,
            'last_error' => "https://source.test/failure?token={$sentinel}",
        ]);
        $favorite = Favorite::query()->create([
            'user_id' => $user->id,
            'game_id' => $game->id,
            'appid' => $game->steam_appid,
            'game_name' => $game->name,
        ]);

        return [$user, $favorite];
    }
}
