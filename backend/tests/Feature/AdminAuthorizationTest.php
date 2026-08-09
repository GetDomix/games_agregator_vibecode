<?php

namespace Tests\Feature;

use App\Models\AdminAuditLog;
use App\Models\Game;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AdminAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('adminReadEndpoints')]
    public function test_admin_read_endpoints_reject_guests_and_users(string $method, string $uri): void
    {
        $this->json($method, $uri)->assertUnauthorized();

        Sanctum::actingAs(User::factory()->create(['admin_role' => User::ROLE_USER]));

        $this->json($method, $uri)->assertForbidden();
    }

    public static function adminReadEndpoints(): array
    {
        return [
            'operational overview' => ['GET', '/api/admin/overview'],
            'user directory' => ['GET', '/api/admin/users'],
            'audit trail' => ['GET', '/api/admin/audit'],
            'admin team' => ['GET', '/api/admin/team'],
        ];
    }

    public function test_admin_write_endpoints_reject_guests_and_users(): void
    {
        Queue::fake();
        $target = User::factory()->create();
        Game::query()->create([
            'steam_appid' => 730,
            'name' => 'Counter-Strike 2',
            'release_status' => 'released',
        ]);
        $endpoints = [
            ['PATCH', "/api/admin/team/{$target->id}", ['role' => User::ROLE_ADMIN]],
            ['POST', '/api/admin/games/730/refresh', ['sources' => ['steam']]],
        ];
        $auditCount = (int) AdminAuditLog::query()->count();

        foreach ($endpoints as [$method, $uri, $payload]) {
            $this->json($method, $uri, $payload)->assertUnauthorized();
        }

        Sanctum::actingAs(User::factory()->create(['admin_role' => User::ROLE_USER]));
        foreach ($endpoints as [$method, $uri, $payload]) {
            $this->json($method, $uri, $payload)->assertForbidden();
        }

        $this->assertSame(User::ROLE_USER, $target->fresh()->admin_role);
        $this->assertDatabaseCount('admin_audit_logs', $auditCount);
        Queue::assertNothingPushed();
    }

    public function test_admin_and_owner_can_read_operational_overview(): void
    {
        foreach ([User::ROLE_ADMIN, User::ROLE_OWNER] as $role) {
            Sanctum::actingAs(User::factory()->create(['admin_role' => $role]));

            $this->getJson('/api/admin/overview')->assertOk();
        }
    }

    public function test_admin_read_limit_is_keyed_per_authenticated_user(): void
    {
        $first = User::factory()->create(['admin_role' => User::ROLE_ADMIN]);
        $second = User::factory()->create(['admin_role' => User::ROLE_ADMIN]);

        Sanctum::actingAs($first);
        for ($i = 0; $i < 120; $i++) {
            $this->getJson('/api/admin/overview')->assertOk();
        }
        $this->getJson('/api/admin/overview')->assertTooManyRequests();

        Sanctum::actingAs($second);
        $this->getJson('/api/admin/overview')->assertOk();
    }

    public function test_admin_action_limit_accepts_twenty_requests_and_rejects_the_twenty_first(): void
    {
        Queue::fake();
        $admin = User::factory()->create(['admin_role' => User::ROLE_ADMIN]);
        Game::query()->create([
            'steam_appid' => 1091500,
            'name' => 'Cyberpunk 2077',
            'release_status' => 'released',
        ]);
        Sanctum::actingAs($admin);
        $auditCount = (int) AdminAuditLog::query()->count();

        for ($i = 0; $i < 20; $i++) {
            $this->postJson('/api/admin/games/1091500/refresh', ['sources' => ['steam']])
                ->assertStatus(202);
        }

        $this->postJson('/api/admin/games/1091500/refresh', ['sources' => ['steam']])
            ->assertTooManyRequests();
        $this->assertDatabaseCount('admin_audit_logs', $auditCount + 20);
    }
}
