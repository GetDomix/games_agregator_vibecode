<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        ];
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
}
