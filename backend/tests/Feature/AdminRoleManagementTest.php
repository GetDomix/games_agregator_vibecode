<?php

namespace Tests\Feature;

use App\Models\AdminAuditLog;
use App\Models\User;
use App\Services\Admin\AdminAuditService;
use App\Services\Admin\AdminRoleService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class AdminRoleManagementTest extends TestCase
{
    use DatabaseTruncation;

    protected function beforeTruncatingDatabase(): void
    {
        $this->artisan('migrate', ['--force' => true]);
    }

    public function test_admin_cannot_change_roles(): void
    {
        Sanctum::actingAs(User::factory()->create(['admin_role' => User::ROLE_ADMIN]));
        $target = User::factory()->create();

        $this->patchJson("/api/admin/team/{$target->id}", ['role' => User::ROLE_ADMIN])
            ->assertForbidden();

        $this->assertSame(User::ROLE_USER, $target->fresh()->admin_role);
    }

    public function test_role_service_rejects_an_admin_without_http_middleware(): void
    {
        $admin = User::factory()->create(['admin_role' => User::ROLE_ADMIN]);
        $target = User::factory()->create();

        try {
            app(AdminRoleService::class)->changeRole($admin, $target, User::ROLE_ADMIN, null);
            $this->fail('An admin must not be able to call the role service directly.');
        } catch (AuthorizationException) {
            $this->assertSame(User::ROLE_USER, $target->fresh()->admin_role);
            $this->assertDatabaseCount('admin_audit_logs', 0);
        }
    }

    public function test_only_owner_can_list_the_complete_admin_team(): void
    {
        $admin = User::factory()->create(['admin_role' => User::ROLE_ADMIN]);
        User::factory()->count(34)->create(['admin_role' => User::ROLE_ADMIN]);
        $owner = User::factory()->create(['admin_role' => User::ROLE_OWNER]);
        User::factory()->create();

        Sanctum::actingAs($admin);
        $this->getJson('/api/admin/team')->assertForbidden();

        Sanctum::actingAs($owner);
        $this->getJson('/api/admin/team')
            ->assertOk()
            ->assertJsonCount(36, 'items')
            ->assertJsonMissing(['password']);
    }

    public function test_configured_owner_is_included_in_the_complete_team_list(): void
    {
        config(['gpa.admin_emails' => 'ROOT@example.com']);
        $root = User::factory()->create(['email' => 'root@example.com']);

        Sanctum::actingAs($root);

        $this->getJson('/api/admin/team')
            ->assertOk()
            ->assertJsonCount(1, 'items')
            ->assertJsonPath('items.0.admin_role', User::ROLE_OWNER);
    }

    public function test_owner_can_promote_admin_and_target_tokens_are_revoked(): void
    {
        $owner = User::factory()->create(['admin_role' => User::ROLE_OWNER]);
        $target = User::factory()->create();
        $token = $target->createToken('web');
        Sanctum::actingAs($owner);

        $this->patchJson("/api/admin/team/{$target->id}", ['role' => User::ROLE_ADMIN])
            ->assertOk()
            ->assertJsonPath('user.admin_role', User::ROLE_ADMIN)
            ->assertJsonMissing(['password', 'remember_token']);

        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $token->accessToken->id]);
        $this->assertDatabaseHas('admin_audit_logs', [
            'actor_id' => $owner->id,
            'action' => 'admin.role_changed',
            'target_id' => (string) $target->id,
        ]);

        $audit = AdminAuditLog::query()->sole();
        $this->assertTrue(Str::isUuid($audit->request_id));
        $this->assertSame([
            'old_role' => User::ROLE_USER,
            'new_role' => User::ROLE_ADMIN,
        ], $audit->context);
    }

    public function test_owner_can_revoke_an_admin_role(): void
    {
        $owner = User::factory()->create(['admin_role' => User::ROLE_OWNER]);
        $target = User::factory()->create(['admin_role' => User::ROLE_ADMIN]);
        Sanctum::actingAs($owner);

        $this->patchJson("/api/admin/team/{$target->id}", ['role' => User::ROLE_USER])
            ->assertOk()
            ->assertJsonPath('user.admin_role', User::ROLE_USER);
    }

    public function test_owner_transition_requires_correct_current_password(): void
    {
        $owner = User::factory()->create(['admin_role' => User::ROLE_OWNER]);
        $target = User::factory()->create();
        Sanctum::actingAs($owner);

        $this->patchJson("/api/admin/team/{$target->id}", ['role' => User::ROLE_OWNER])
            ->assertUnprocessable();
        $this->patchJson("/api/admin/team/{$target->id}", [
            'role' => User::ROLE_OWNER,
            'current_password' => 'wrong',
        ])->assertUnprocessable();
        $this->patchJson("/api/admin/team/{$target->id}", [
            'role' => User::ROLE_OWNER,
            'current_password' => 'password',
        ])->assertOk();
    }

    public function test_demoting_an_owner_requires_correct_current_password(): void
    {
        $actor = User::factory()->create(['admin_role' => User::ROLE_OWNER]);
        $target = User::factory()->create(['admin_role' => User::ROLE_OWNER]);
        Sanctum::actingAs($actor);

        $this->patchJson("/api/admin/team/{$target->id}", ['role' => User::ROLE_ADMIN])
            ->assertUnprocessable();
        $this->patchJson("/api/admin/team/{$target->id}", [
            'role' => User::ROLE_ADMIN,
            'current_password' => 'wrong',
        ])->assertUnprocessable();
        $this->patchJson("/api/admin/team/{$target->id}", [
            'role' => User::ROLE_ADMIN,
            'current_password' => 'password',
        ])->assertOk();
    }

    public function test_audit_service_discards_context_keys_not_allowlisted_for_the_action(): void
    {
        $actor = User::factory()->create(['admin_role' => User::ROLE_OWNER]);

        $audit = app(AdminAuditService::class)->record(
            $actor,
            'admin.role_changed',
            'user',
            '42',
            [
                'old_role' => User::ROLE_USER,
                'new_role' => User::ROLE_ADMIN,
                'current_password' => 'must-never-be-stored',
                'unexpected' => 'must-never-be-stored',
            ],
        );

        $this->assertSame([
            'old_role' => User::ROLE_USER,
            'new_role' => User::ROLE_ADMIN,
        ], $audit->context);
    }

    public function test_last_effective_owner_cannot_be_demoted(): void
    {
        config(['gpa.admin_emails' => '']);
        $owner = User::factory()->create(['admin_role' => User::ROLE_OWNER]);
        Sanctum::actingAs($owner);

        $this->patchJson("/api/admin/team/{$owner->id}", [
            'role' => User::ROLE_ADMIN,
            'current_password' => 'password',
        ])->assertUnprocessable();

        $this->assertSame(User::ROLE_OWNER, $owner->fresh()->admin_role);
    }

    public function test_server_managed_owner_cannot_be_demoted(): void
    {
        config(['gpa.admin_emails' => 'root@example.com']);
        $root = User::factory()->create(['email' => 'root@example.com']);
        Sanctum::actingAs($root);

        $this->patchJson("/api/admin/team/{$root->id}", [
            'role' => User::ROLE_USER,
            'current_password' => 'password',
        ])->assertUnprocessable();

        $this->assertSame(User::ROLE_OWNER, $root->fresh()->effectiveAdminRole());
    }

    public function test_role_request_rejects_unknown_fields_before_changing_state(): void
    {
        $owner = User::factory()->create(['admin_role' => User::ROLE_OWNER]);
        $target = User::factory()->create();
        Sanctum::actingAs($owner);

        $this->patchJson("/api/admin/team/{$target->id}", [
            'role' => User::ROLE_ADMIN,
            'password' => 'must-not-be-accepted',
        ])->assertUnprocessable()->assertJsonValidationErrors('request');

        $this->assertSame(User::ROLE_USER, $target->fresh()->admin_role);
        $this->assertDatabaseCount('admin_audit_logs', 0);
    }

    public function test_role_request_rejects_unknown_roles(): void
    {
        $owner = User::factory()->create(['admin_role' => User::ROLE_OWNER]);
        $target = User::factory()->create();
        Sanctum::actingAs($owner);

        $this->patchJson("/api/admin/team/{$target->id}", ['role' => 'super-admin'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('role');

        $this->assertSame(User::ROLE_USER, $target->fresh()->admin_role);
    }

    public function test_legacy_boolean_admin_endpoint_is_removed(): void
    {
        $owner = User::factory()->create(['admin_role' => User::ROLE_OWNER]);
        $target = User::factory()->create();
        Sanctum::actingAs($owner);

        $this->postJson("/api/admin/users/{$target->id}/admin", ['is_admin' => true])
            ->assertNotFound();

        $this->assertSame(User::ROLE_USER, $target->fresh()->admin_role);
    }

    public function test_role_changes_are_limited_to_five_per_minute(): void
    {
        $owner = User::factory()->create(['admin_role' => User::ROLE_OWNER]);
        $target = User::factory()->create();
        Sanctum::actingAs($owner);

        for ($i = 0; $i < 5; $i++) {
            $this->patchJson("/api/admin/team/{$target->id}", ['role' => User::ROLE_ADMIN])
                ->assertOk();
        }

        $this->patchJson("/api/admin/team/{$target->id}", ['role' => User::ROLE_ADMIN])
            ->assertTooManyRequests();
    }

    public function test_concurrent_demotions_cannot_remove_the_last_effective_owner(): void
    {
        if (config('database.default') !== 'pgsql') {
            $this->markTestSkipped('Requires PostgreSQL transactions and row-lock behavior.');
        }
        if (! function_exists('proc_open')) {
            $this->markTestSkipped('Requires proc_open to run two independent database clients.');
        }

        config(['gpa.admin_emails' => '']);
        $owners = User::factory()->count(2)->create(['admin_role' => User::ROLE_OWNER]);
        $barrier = sys_get_temp_dir().'/igroscan-owner-race-'.Str::uuid();
        mkdir($barrier, 0700);

        $worker = <<<'PHP'
$basePath = $argv[1];
$actorId = (int) $argv[2];
$readyPath = $argv[3];
$goPath = $argv[4];
require $basePath.'/vendor/autoload.php';
$app = require $basePath.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
config(['gpa.admin_emails' => '']);
App\Models\User::updating(function (): void {
    Illuminate\Support\Facades\DB::select('SELECT pg_sleep(0.5)');
});
file_put_contents($readyPath, 'ready');
$deadline = microtime(true) + 10;
while (! is_file($goPath) && microtime(true) < $deadline) {
    usleep(10000);
}
if (! is_file($goPath)) {
    fwrite(STDERR, 'barrier timeout');
    exit(2);
}
try {
    $actor = App\Models\User::query()->findOrFail($actorId);
    app(App\Services\Admin\AdminRoleService::class)->changeRole(
        $actor,
        $actor,
        App\Models\User::ROLE_ADMIN,
        'password',
    );
    echo 'success';
} catch (Illuminate\Validation\ValidationException|Illuminate\Auth\Access\AuthorizationException $exception) {
    echo 'rejected';
} catch (Throwable $exception) {
    fwrite(STDERR, $exception::class.': '.$exception->getMessage());
    exit(3);
}
PHP;

        $processes = [];
        try {
            foreach ($owners as $index => $owner) {
                $process = new Process([
                    PHP_BINARY,
                    '-r',
                    $worker,
                    base_path(),
                    (string) $owner->id,
                    "{$barrier}/ready-{$index}",
                    "{$barrier}/go",
                ], base_path());
                $process->start();
                $processes[] = $process;
            }

            $deadline = microtime(true) + 10;
            while ((! is_file("{$barrier}/ready-0") || ! is_file("{$barrier}/ready-1")) && microtime(true) < $deadline) {
                usleep(10000);
            }
            $this->assertFileExists("{$barrier}/ready-0", 'First database client did not reach the barrier.');
            $this->assertFileExists("{$barrier}/ready-1", 'Second database client did not reach the barrier.');
            file_put_contents("{$barrier}/go", 'go');

            $outcomes = [];
            foreach ($processes as $process) {
                $process->wait();
                $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
                $outcomes[] = trim($process->getOutput());
            }

            sort($outcomes);
            $this->assertSame(['rejected', 'success'], $outcomes);
            $this->assertSame(1, User::query()->get()->filter->canManageAdminTeam()->count());
        } finally {
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop();
                }
            }
            foreach (['ready-0', 'ready-1', 'go'] as $file) {
                @unlink("{$barrier}/{$file}");
            }
            @rmdir($barrier);
        }
    }
}
