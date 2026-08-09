<?php

namespace Tests\Feature;

use App\Models\AdminAuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
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
}
