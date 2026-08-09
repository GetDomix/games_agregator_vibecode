<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AdminRoleMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_upgrade_maps_legacy_admins_and_removes_boolean_flag(): void
    {
        $migration = require database_path('migrations/2026_08_09_130000_replace_is_admin_with_admin_role.php');
        $migration->down();
        $legacyAdminId = DB::table('users')->insertGetId([
            'name' => 'Legacy Admin', 'email' => 'legacy-admin@example.com',
            'password' => Hash::make('password'), 'is_admin' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $legacyUserId = DB::table('users')->insertGetId([
            'name' => 'Legacy User', 'email' => 'legacy-user@example.com',
            'password' => Hash::make('password'), 'is_admin' => false,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $migration->up();

        $this->assertSame('admin', DB::table('users')->where('id', $legacyAdminId)->value('admin_role'));
        $this->assertSame('user', DB::table('users')->where('id', $legacyUserId)->value('admin_role'));
        $this->assertFalse(Schema::hasColumn('users', 'is_admin'));
    }

    public function test_configured_email_has_effective_owner_role_without_database_promotion(): void
    {
        config(['gpa.admin_emails' => 'owner@example.com']);
        $user = User::factory()->create(['email' => 'OWNER@example.com', 'admin_role' => User::ROLE_USER]);

        $this->assertSame(User::ROLE_OWNER, $user->effectiveAdminRole());
        $this->assertTrue($user->canManageAdminTeam());
        $this->assertTrue($user->isServerManagedOwner());
    }

    public function test_public_user_payload_exposes_capabilities_but_no_legacy_flag(): void
    {
        $user = User::factory()->create(['admin_role' => User::ROLE_ADMIN]);
        $payload = $user->toPublicArray();

        $this->assertSame('admin', $payload['admin_role']);
        $this->assertTrue($payload['can_access_admin']);
        $this->assertFalse($payload['can_manage_admin_team']);
        $this->assertArrayNotHasKey('is_admin', $payload);
    }
}
