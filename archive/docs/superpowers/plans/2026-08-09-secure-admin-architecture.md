# Secure Admin Architecture Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Заменить плоский флаг администратора на защищённые роли `user/admin/owner`, разделить административный backend по ответственности, добавить управление командой владельцами и воспроизводимый security-набор.

**Architecture:** Эффективная роль вычисляется моделью `User`, доступ обеспечивают role middleware, переходы ролей выполняет транзакционный `AdminRoleService`, а метрики, каталог пользователей и аудит обслуживают отдельные сервисы. React-админка становится оболочкой с пятью вкладками и изолированным диалогом смены роли.

**Tech Stack:** PHP 8.4, Laravel 13, Sanctum 4, PostgreSQL 16, PHPUnit 12, React 19, TypeScript 6, Vite 8, Vitest, Testing Library.

## Global Constraints

- Роли имеют только значения `user`, `admin`, `owner`.
- Только эффективный `owner` может менять роли.
- Пользователь из `ADMIN_EMAILS` всегда является владельцем и не может быть понижен через API.
- Нельзя оставить систему без эффективного владельца; проверка должна быть безопасна при параллельных запросах PostgreSQL.
- Назначение или снятие `owner` требует текущий пароль инициатора.
- После успешной смены роли все Sanctum-токены цели отзываются.
- Пароли, access tokens, Telegram chat ID, exception bodies и полные заголовки не попадают в API или аудит.
- Административное чтение, операционные изменения и управление ролями используют отдельные rate limits.
- Каждый production-шаг выполняется только после теста, который был запущен и упал по ожидаемой причине.
- Существующие пользовательские изменения в dirty worktree сохраняются; каждый коммит содержит только файлы своей задачи.

## File Map

**Backend data and authorization**

- Create `backend/database/migrations/2026_08_09_130000_replace_is_admin_with_admin_role.php` — backfill и constraint ролей.
- Modify `backend/app/Models/User.php` — role constants, `effectiveAdminRole()`, `canAccessAdmin()`, `canManageAdminTeam()`.
- Create `backend/app/Http/Middleware/EnsureAdminRole.php` — gate для `admin|owner`.
- Create `backend/app/Http/Middleware/EnsureOwnerRole.php` — gate для `owner`.
- Modify `backend/bootstrap/app.php` — middleware aliases.
- Modify `backend/app/Providers/AppServiceProvider.php` — `admin-read`, `admin-action`, `admin-role` limiters.

**Backend services and API**

- Create `backend/app/Services/Admin/AdminAuditService.php` — безопасная запись и чтение аудита.
- Create `backend/app/Services/Admin/AdminRoleService.php` — транзакционный переход ролей.
- Create `backend/app/Services/Admin/AdminOverviewService.php` — агрегированные operational metrics.
- Create `backend/app/Services/Admin/AdminUserDirectoryService.php` — экранированный поиск и безопасная сериализация.
- Create `backend/app/Http/Controllers/Api/AdminTeamController.php` — owner-only role endpoint.
- Create `backend/app/Http/Controllers/Api/AdminAuditController.php` — paginated audit endpoint.
- Modify `backend/app/Http/Controllers/Api/AdminController.php` — тонкий orchestration controller.
- Modify `backend/routes/api.php` — role groups и удаление legacy role endpoint.
- Create `backend/database/migrations/2026_08_09_131000_add_request_id_to_admin_audit_logs.php` — indexed request UUID.
- Modify `backend/app/Models/AdminAuditLog.php` — `request_id` и безопасные casts/fillable.

**Frontend**

- Create `frontend/src/admin/types.ts` — API contracts.
- Create `frontend/src/admin/AdminShell.tsx` — tabs, loading and refresh orchestration.
- Create `frontend/src/admin/AdminOverviewTab.tsx` — KPI и health.
- Create `frontend/src/admin/AdminCatalogTab.tsx` — queries и refresh form.
- Create `frontend/src/admin/AdminUsersTab.tsx` — directory.
- Create `frontend/src/admin/AdminTeamTab.tsx` — owner-only team management.
- Create `frontend/src/admin/AdminAuditTab.tsx` — paginated timeline.
- Create `frontend/src/admin/RoleChangeDialog.tsx` — password-gated confirmation.
- Modify `frontend/src/components/AdminPanel.tsx` — compatibility wrapper around `AdminShell`.
- Modify `frontend/src/api.ts` и `frontend/src/App.tsx` — role-aware session contract.
- Modify `frontend/src/styles.css` — tabs, team cards, dialog and responsive states.
- Modify `frontend/package.json` и `frontend/package-lock.json` — Vitest/Testing Library.

**Tests and docs**

- Create `backend/tests/Feature/AdminRoleMigrationTest.php`.
- Create `backend/tests/Feature/AdminAuthorizationTest.php`.
- Create `backend/tests/Feature/AdminRoleManagementTest.php`.
- Create `backend/tests/Feature/AdminSecurityTest.php`.
- Modify `backend/tests/Feature/AdminOperationsTest.php`.
- Create `frontend/src/admin/AdminShell.test.tsx`.
- Create `frontend/src/admin/RoleChangeDialog.test.tsx`.
- Create `docs/SECURITY_REVIEW.md`.
- Modify `README.md`, `.env.example` и `backend/.env.example`.

---

### Task 1: Role Data Model and Safe Migration

**Files:**
- Create: `backend/tests/Feature/AdminRoleMigrationTest.php`
- Create: `backend/database/migrations/2026_08_09_130000_replace_is_admin_with_admin_role.php`
- Modify: `backend/app/Models/User.php`
- Modify: `backend/database/factories/UserFactory.php`

**Interfaces:**
- Produces: `User::ROLE_USER`, `ROLE_ADMIN`, `ROLE_OWNER`, `ADMIN_ROLES`.
- Produces: `User::effectiveAdminRole(): string`, `canAccessAdmin(): bool`, `canManageAdminTeam(): bool`, `isServerManagedOwner(): bool`.
- Produces API fields: `admin_role`, `can_access_admin`, `can_manage_admin_team`; removes `is_admin` from public JSON.

- [ ] **Step 1: Write migration and effective-role tests**

```php
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
```

- [ ] **Step 2: Run tests and verify RED**

Run: `cd backend && php artisan test --filter=AdminRoleMigrationTest`

Expected: failures for missing migration, `admin_role` column and role methods. The break caught is a migration that loses existing administrators or a model that trusts only the database field.

- [ ] **Step 3: Implement the migration and role methods**

Migration behavior:

```php
Schema::table('users', fn (Blueprint $table) => $table->string('admin_role', 20)->default('user')->index());
DB::table('users')->where('is_admin', true)->update(['admin_role' => 'admin']);
Schema::table('users', fn (Blueprint $table) => $table->dropColumn('is_admin'));
if (DB::getDriverName() === 'pgsql') {
    DB::statement("ALTER TABLE users ADD CONSTRAINT users_admin_role_check CHECK (admin_role IN ('user','admin','owner'))");
}
```

Model behavior:

```php
public function effectiveAdminRole(): string
{
    return $this->isServerManagedOwner() ? self::ROLE_OWNER : ($this->admin_role ?: self::ROLE_USER);
}

public function canAccessAdmin(): bool
{
    return in_array($this->effectiveAdminRole(), [self::ROLE_ADMIN, self::ROLE_OWNER], true);
}

public function canManageAdminTeam(): bool
{
    return $this->effectiveAdminRole() === self::ROLE_OWNER;
}
```

`isServerManagedOwner()` parses `config('gpa.admin_emails')`, trims values and compares lower-cased emails exactly. `down()` recreates `is_admin`, maps `admin|owner` to `true`, drops the PostgreSQL constraint and then drops `admin_role`.

- [ ] **Step 4: Run focused tests and existing auth smoke tests**

Run: `cd backend && php artisan test --filter='AdminRoleMigrationTest|ApiSmokeTest|TelegramOidcBeginTest'`

Expected: PASS; authentication payload consumers continue to receive valid role capabilities.

- [ ] **Step 5: Commit**

```bash
git add backend/app/Models/User.php backend/database/factories/UserFactory.php backend/database/migrations/2026_08_09_130000_replace_is_admin_with_admin_role.php backend/tests/Feature/AdminRoleMigrationTest.php
git commit -m "feat: add owner and admin role model"
```

---

### Task 2: Central Role Middleware and Administrative Rate Limits

**Files:**
- Create: `backend/tests/Feature/AdminAuthorizationTest.php`
- Create: `backend/app/Http/Middleware/EnsureAdminRole.php`
- Create: `backend/app/Http/Middleware/EnsureOwnerRole.php`
- Modify: `backend/bootstrap/app.php`
- Modify: `backend/app/Providers/AppServiceProvider.php`
- Modify: `backend/routes/api.php`
- Modify: `backend/app/Http/Controllers/Api/AdminController.php`

**Interfaces:**
- Consumes: `User::canAccessAdmin()` and `User::canManageAdminTeam()` from Task 1.
- Produces middleware aliases: `admin-role`, `owner-role`.
- Produces named limiters: `admin-read` = 120/minute, `admin-action` = 20/minute, `admin-role` = 5/minute, keyed by authenticated user ID with IP fallback.

- [ ] **Step 1: Write authorization boundary tests**

```php
#[DataProvider('adminReadEndpoints')]
public function test_admin_read_endpoints_reject_guests_and_users(string $method, string $uri): void
{
    $this->json($method, $uri)->assertUnauthorized();
    Sanctum::actingAs(User::factory()->create(['admin_role' => User::ROLE_USER]));
    $this->json($method, $uri)->assertForbidden();
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
    for ($i = 0; $i < 120; $i++) $this->getJson('/api/admin/overview')->assertOk();
    $this->getJson('/api/admin/overview')->assertTooManyRequests();
    Sanctum::actingAs($second);
    $this->getJson('/api/admin/overview')->assertOk();
}
```

Data provider includes `GET /api/admin/overview` and `GET /api/admin/users`.

- [ ] **Step 2: Run tests and verify RED**

Run: `cd backend && php artisan test --filter=AdminAuthorizationTest`

Expected: missing middleware aliases/limiters or incorrect access results. The break caught is a route accidentally leaving the protected group or sharing an IP-only bucket.

- [ ] **Step 3: Implement middleware, aliases and route groups**

Middleware handle contract:

```php
public function handle(Request $request, Closure $next): Response
{
    abort_unless($request->user()?->canAccessAdmin(), 403, 'Доступ только для администрации');
    return $next($request);
}
```

`EnsureOwnerRole` uses `canManageAdminTeam()`. Register aliases in `bootstrap/app.php` through `$middleware->alias([...])`. Define limiters in `AppServiceProvider`:

```php
RateLimiter::for('admin-read', fn ($request) => Limit::perMinute(120)->by('admin-read:'.($request->user()?->id ?: $request->ip())));
RateLimiter::for('admin-action', fn ($request) => Limit::perMinute(20)->by('admin-action:'.($request->user()?->id ?: $request->ip())));
RateLimiter::for('admin-role', fn ($request) => Limit::perMinute(5)->by('admin-role:'.($request->user()?->id ?: $request->ip())));
```

Wrap read routes with `['admin-role', 'throttle:admin-read']`; wrap game refresh with `['admin-role', 'throttle:admin-action']`. Delete `authorizeAdmin()` and its calls from `AdminController` only after the routes are protected.

- [ ] **Step 4: Run authorization tests and route inspection**

Run: `cd backend && php artisan test --filter='AdminAuthorizationTest|AdminOperationsTest'`

Run: `cd backend && php artisan route:list --path=api/admin -v`

Expected: PASS and every admin route lists auth, role and appropriate throttle middleware.

- [ ] **Step 5: Commit**

```bash
git add backend/app/Http/Middleware/EnsureAdminRole.php backend/app/Http/Middleware/EnsureOwnerRole.php backend/bootstrap/app.php backend/app/Providers/AppServiceProvider.php backend/routes/api.php backend/app/Http/Controllers/Api/AdminController.php backend/tests/Feature/AdminAuthorizationTest.php
git commit -m "security: centralize admin authorization"
```

---

### Task 3: Transactional Owner-Only Role Management

**Files:**
- Create: `backend/tests/Feature/AdminRoleManagementTest.php`
- Create: `backend/app/Services/Admin/AdminAuditService.php`
- Create: `backend/app/Services/Admin/AdminRoleService.php`
- Create: `backend/app/Http/Controllers/Api/AdminTeamController.php`
- Create: `backend/database/migrations/2026_08_09_131000_add_request_id_to_admin_audit_logs.php`
- Modify: `backend/app/Models/AdminAuditLog.php`
- Modify: `backend/routes/api.php`
- Remove route: `POST /api/admin/users/{id}/admin`

**Interfaces:**
- Consumes role methods and owner middleware from Tasks 1–2.
- Produces `AdminRoleService::changeRole(User $actor, User $target, string $newRole, ?string $currentPassword): User`.
- Produces `AdminAuditService::record(User $actor, string $action, string $targetType, string $targetId, array $context = []): AdminAuditLog`.
- Produces `GET /api/admin/team` with every effective `admin|owner`, available only to owners.
- Produces `PATCH /api/admin/team/{user}` with `{role, current_password?}`.

- [ ] **Step 1: Write role-transition security tests**

```php
public function test_admin_cannot_change_roles(): void
{
    Sanctum::actingAs(User::factory()->create(['admin_role' => User::ROLE_ADMIN]));
    $target = User::factory()->create();
    $this->patchJson("/api/admin/team/{$target->id}", ['role' => 'admin'])->assertForbidden();
    $this->assertSame(User::ROLE_USER, $target->fresh()->admin_role);
}

public function test_only_owner_can_list_the_complete_admin_team(): void
{
    User::factory()->count(35)->create(['admin_role' => User::ROLE_ADMIN]);
    $owner = User::factory()->create(['admin_role' => User::ROLE_OWNER]);
    Sanctum::actingAs($owner);
    $this->getJson('/api/admin/team')->assertOk()->assertJsonCount(36, 'items');
}

public function test_owner_can_promote_admin_and_target_tokens_are_revoked(): void
{
    $owner = User::factory()->create(['admin_role' => User::ROLE_OWNER]);
    $target = User::factory()->create();
    $token = $target->createToken('web');
    Sanctum::actingAs($owner);

    $this->patchJson("/api/admin/team/{$target->id}", ['role' => 'admin'])
        ->assertOk()->assertJsonPath('user.admin_role', 'admin');

    $this->assertDatabaseMissing('personal_access_tokens', ['id' => $token->accessToken->id]);
    $this->assertDatabaseHas('admin_audit_logs', [
        'actor_id' => $owner->id, 'action' => 'admin.role_changed', 'target_id' => (string) $target->id,
    ]);
}

public function test_owner_transition_requires_correct_current_password(): void
{
    $owner = User::factory()->create(['admin_role' => User::ROLE_OWNER]);
    $target = User::factory()->create();
    Sanctum::actingAs($owner);

    $this->patchJson("/api/admin/team/{$target->id}", ['role' => 'owner'])->assertStatus(422);
    $this->patchJson("/api/admin/team/{$target->id}", ['role' => 'owner', 'current_password' => 'wrong'])->assertStatus(422);
    $this->patchJson("/api/admin/team/{$target->id}", ['role' => 'owner', 'current_password' => 'password'])->assertOk();
}

public function test_last_effective_owner_cannot_be_demoted(): void
{
    config(['gpa.admin_emails' => '']);
    $owner = User::factory()->create(['admin_role' => User::ROLE_OWNER]);
    Sanctum::actingAs($owner);
    $this->patchJson("/api/admin/team/{$owner->id}", ['role' => 'admin', 'current_password' => 'password'])
        ->assertStatus(422);
    $this->assertSame(User::ROLE_OWNER, $owner->fresh()->admin_role);
}

public function test_server_managed_owner_cannot_be_demoted(): void
{
    config(['gpa.admin_emails' => 'root@example.com']);
    $root = User::factory()->create(['email' => 'root@example.com']);
    Sanctum::actingAs($root);
    $this->patchJson("/api/admin/team/{$root->id}", ['role' => 'user', 'current_password' => 'password'])
        ->assertStatus(422);
}
```

Add a PostgreSQL-only concurrency test using two database connections and transactions: both attempt to demote one of two owners; after both complete, `effectiveAdminRole()` still reports at least one owner. Skip with an explicit message when the driver is not `pgsql`.

- [ ] **Step 2: Run tests and verify RED**

Run: `cd backend && php artisan test --filter=AdminRoleManagementTest`

Expected: 404 for the missing team route and failures for missing role service. Each test catches an unauthorized transition, missing password gate, lost last owner, missing token revocation or missing audit side effect.

- [ ] **Step 3: Implement audit storage and role service**

Add `request_id` UUID/string column and index. `AdminAuditService::record()` generates `Str::uuid()->toString()` and allowlists context keys per action.

`AdminRoleService` outline:

```php
return DB::transaction(function () use ($actor, $target, $newRole, $currentPassword) {
    $lockedTarget = User::query()->lockForUpdate()->findOrFail($target->id);
    $lockedOwners = User::query()
        ->where('admin_role', User::ROLE_OWNER)
        ->orWhereIn(DB::raw('LOWER(email)'), $this->configuredOwnerEmails())
        ->lockForUpdate()->get();

    if ($lockedTarget->isServerManagedOwner()) {
        throw ValidationException::withMessages(['role' => ['Этот владелец управляется серверной конфигурацией']]);
    }
    $oldRole = $lockedTarget->effectiveAdminRole();
    if ($oldRole === User::ROLE_OWNER || $newRole === User::ROLE_OWNER) {
        $this->verifyPassword($actor, $currentPassword);
    }
    $this->guardLastOwner($lockedOwners, $lockedTarget, $newRole);
    $lockedTarget->forceFill(['admin_role' => $newRole])->save();
    $lockedTarget->tokens()->delete();
    $this->audit->record($actor, 'admin.role_changed', 'user', (string) $lockedTarget->id, [
        'old_role' => $oldRole, 'new_role' => $newRole,
    ]);
    return $lockedTarget->refresh();
}, attempts: 3);
```

Reject unknown request keys before validation with `array_diff(array_keys($request->all()), ['role', 'current_password'])`. Validate role through `Rule::in(User::ADMIN_ROLES)`. Return only the safe public user payload.

- [ ] **Step 4: Run role tests twice and inspect audit payloads**

Run once: `cd backend && php artisan test --filter=AdminRoleManagementTest`

Run a second time: `cd backend && php artisan test --filter=AdminRoleManagementTest`

Expected: PASS on both independent runs; audit context contains only `old_role` and `new_role`, and role-changing requests are capped by `throttle:admin-role`.

- [ ] **Step 5: Commit**

```bash
git add backend/app/Services/Admin/AdminAuditService.php backend/app/Services/Admin/AdminRoleService.php backend/app/Http/Controllers/Api/AdminTeamController.php backend/app/Models/AdminAuditLog.php backend/database/migrations/2026_08_09_131000_add_request_id_to_admin_audit_logs.php backend/routes/api.php backend/tests/Feature/AdminRoleManagementTest.php
git commit -m "feat: add owner-only admin team management"
```

---

### Task 4: Split Admin Queries and Add Safe Audit API

**Files:**
- Modify: `backend/tests/Feature/AdminOperationsTest.php`
- Create: `backend/tests/Feature/AdminSecurityTest.php`
- Create: `backend/app/Services/Admin/AdminOverviewService.php`
- Create: `backend/app/Services/Admin/AdminUserDirectoryService.php`
- Create: `backend/app/Http/Controllers/Api/AdminAuditController.php`
- Modify: `backend/app/Services/Admin/AdminAuditService.php`
- Modify: `backend/app/Http/Controllers/Api/AdminController.php`
- Modify: `backend/routes/api.php`

**Interfaces:**
- Produces `AdminOverviewService::build(): array` with the existing overview contract.
- Produces `AdminUserDirectoryService::search(string $term, int $perPage = 30): LengthAwarePaginator`.
- Produces `AdminAuditService::paginateFor(User $viewer, int $perPage = 25): LengthAwarePaginator`.
- Produces `GET /api/admin/audit?page=N`.

- [ ] **Step 1: Write safe-query and disclosure tests**

```php
public function test_user_search_treats_sql_and_wildcard_payloads_as_literal_text(): void
{
    $owner = User::factory()->create(['admin_role' => User::ROLE_OWNER]);
    User::factory()->create(['email' => 'percent@example.com', 'display_name' => '100% Player']);
    User::factory()->create(['email' => 'ordinary@example.com', 'display_name' => 'Ordinary']);
    Sanctum::actingAs($owner);

    $this->getJson('/api/admin/users?q='.urlencode("%' OR 1=1 --"))->assertOk()->assertJsonCount(0, 'items');
    $this->getJson('/api/admin/users?q='.urlencode('100%'))->assertOk()->assertJsonCount(1, 'items');
}

public function test_admin_user_and_audit_payloads_never_expose_secrets(): void
{
    $owner = User::factory()->create(['admin_role' => User::ROLE_OWNER, 'telegram_chat_id' => '123456']);
    Sanctum::actingAs($owner);
    foreach (['/api/admin/users', '/api/admin/audit'] as $uri) {
        $json = $this->getJson($uri)->assertOk()->getContent();
        $this->assertStringNotContainsString('password', $json);
        $this->assertStringNotContainsString('remember_token', $json);
        $this->assertStringNotContainsString('telegram_chat_id', $json);
        $this->assertStringNotContainsString('personal_access_tokens', $json);
    }
}

public function test_admin_cannot_see_role_change_audit_but_owner_can(): void
{
    $owner = User::factory()->create(['admin_role' => User::ROLE_OWNER]);
    $admin = User::factory()->create(['admin_role' => User::ROLE_ADMIN]);
    AdminAuditLog::query()->create(['actor_id' => $owner->id, 'action' => 'admin.role_changed', 'target_type' => 'user', 'target_id' => '42', 'request_id' => Str::uuid()]);

    Sanctum::actingAs($admin);
    $this->getJson('/api/admin/audit')->assertOk()->assertJsonCount(0, 'data');
    Sanctum::actingAs($owner);
    $this->getJson('/api/admin/audit')->assertOk()->assertJsonCount(1, 'data');
}
```

Update operations tests to assert the same response contract after controller extraction and paginated user results under `data`/`meta`.

- [ ] **Step 2: Run focused tests and verify RED**

Run: `cd backend && php artisan test --filter='AdminSecurityTest|AdminOperationsTest'`

Expected: wildcard query returns too many rows, audit route is absent, or sensitive-role filtering is absent. The tests catch injection-adjacent query broadening and accidental serializer leaks.

- [ ] **Step 3: Extract services and implement literal LIKE search**

Search normalization:

```php
$literal = addcslashes(mb_strtolower(trim($term)), '\\%_');
$pattern = "%{$literal}%";
$query->whereRaw("LOWER(email) LIKE ? ESCAPE '\\\\'", [$pattern]);
```

Keep numeric exact-ID search as a separate `orWhere('id', (int) $term)` branch. Select an explicit field allowlist; do not serialize Eloquent models directly. `AdminController` delegates overview and directory work to services.

`paginateFor()` excludes actions beginning with `admin.role_` for viewers without `canManageAdminTeam()`. The audit controller validates `per_page` as integer `1..50` and returns Laravel pagination JSON.

- [ ] **Step 4: Run extracted-service and security tests**

Run: `cd backend && php artisan test --filter='AdminOperationsTest|AdminSecurityTest|AdminAuthorizationTest|AdminRoleManagementTest'`

Expected: PASS; controller extraction does not alter metrics or access boundaries.

- [ ] **Step 5: Commit**

```bash
git add backend/app/Services/Admin/AdminOverviewService.php backend/app/Services/Admin/AdminUserDirectoryService.php backend/app/Services/Admin/AdminAuditService.php backend/app/Http/Controllers/Api/AdminController.php backend/app/Http/Controllers/Api/AdminAuditController.php backend/routes/api.php backend/tests/Feature/AdminOperationsTest.php backend/tests/Feature/AdminSecurityTest.php
git commit -m "refactor: isolate secure admin query services"
```

---

### Task 5: Modular Role-Aware Admin Interface

**Files:**
- Modify: `frontend/package.json`
- Modify: `frontend/package-lock.json`
- Create: `frontend/src/admin/types.ts`
- Create: `frontend/src/admin/AdminShell.tsx`
- Create: `frontend/src/admin/AdminOverviewTab.tsx`
- Create: `frontend/src/admin/AdminCatalogTab.tsx`
- Create: `frontend/src/admin/AdminUsersTab.tsx`
- Create: `frontend/src/admin/AdminTeamTab.tsx`
- Create: `frontend/src/admin/AdminAuditTab.tsx`
- Create: `frontend/src/admin/RoleChangeDialog.tsx`
- Create: `frontend/src/admin/AdminShell.test.tsx`
- Create: `frontend/src/admin/RoleChangeDialog.test.tsx`
- Modify: `frontend/src/components/AdminPanel.tsx`
- Modify: `frontend/src/api.ts`
- Modify: `frontend/src/App.tsx`
- Modify: `frontend/src/styles.css`

**Interfaces:**
- Consumes API contracts from Tasks 1, 3 and 4.
- Produces `AdminShell({ currentUser }: { currentUser: User })`.
- Produces `RoleChangeDialog({ target, nextRole, onCancel, onConfirm })` where `onConfirm(currentPassword?: string): Promise<void>`.
- Produces tabs `overview|catalog|users|team|audit`; `team` is absent unless `currentUser.can_manage_admin_team`.

- [ ] **Step 1: Install test tooling and write failing UI tests**

Run: `cd frontend && npm install -D vitest jsdom @testing-library/react @testing-library/user-event @testing-library/jest-dom`

Add scripts: `"test": "vitest run"`, `"test:watch": "vitest"` and configure Vitest with `environment: 'jsdom'` plus a setup file importing `@testing-library/jest-dom/vitest`.

```tsx
it('does not render Team for an admin', async () => {
  render(<AdminShell currentUser={{ ...adminUser, admin_role: 'admin', can_access_admin: true, can_manage_admin_team: false }} />)
  expect(screen.queryByRole('tab', { name: 'Команда' })).not.toBeInTheDocument()
})

it('renders Team for an owner', async () => {
  render(<AdminShell currentUser={{ ...ownerUser, admin_role: 'owner', can_access_admin: true, can_manage_admin_team: true }} />)
  expect(await screen.findByRole('tab', { name: 'Команда' })).toBeInTheDocument()
})

it('requires password when either side of transition is owner', async () => {
  const user = userEvent.setup()
  render(<RoleChangeDialog target={{ ...target, admin_role: 'admin' }} nextRole="owner" onCancel={vi.fn()} onConfirm={vi.fn()} />)
  expect(screen.getByLabelText('Текущий пароль')).toBeRequired()
  await user.click(screen.getByRole('button', { name: 'Подтвердить' }))
  expect(screen.getByRole('alert')).toHaveTextContent('Введите текущий пароль')
})
```

Use an MSW-free `vi.stubGlobal('fetch', vi.fn())` response double matching the complete `{stats, operations, failures, searches, audit}` contracts. Assert rendered behavior, not fetch call counts.

- [ ] **Step 2: Run UI tests and verify RED**

Run: `cd frontend && npm test -- AdminShell.test.tsx RoleChangeDialog.test.tsx`

Expected: module-not-found failures for the new components. The break caught is exposing team controls to an admin or allowing owner transitions without password confirmation.

- [ ] **Step 3: Implement typed shell, tabs and role dialog**

`frontend/src/admin/types.ts` defines `AdminRole = 'user' | 'admin' | 'owner'`, safe user/team/audit types and paginated response types. Update `api.ts` `User` to require `admin_role`, `can_access_admin`, `can_manage_admin_team` and remove `is_admin`.

`AdminShell` owns selected tab and shared error/notice state. Each tab owns only its endpoint state. `AdminTeamTab` calls:

```ts
await api(`/api/admin/team/${target.id}`, {
  method: 'PATCH',
  body: JSON.stringify({ role: nextRole, ...(password ? { current_password: password } : {}) }),
})
```

`RoleChangeDialog` clears password state on close and never stores it outside component memory. Disable submit while pending. Render backend `403`, `422` and `429` messages in an alert region. `AdminPanel` becomes a one-line wrapper or is replaced directly from `App.tsx`.

- [ ] **Step 4: Run UI tests, lint and production build**

Run: `cd frontend && npm test`

Run: `cd frontend && npm run lint && npm run build`

Expected: all tests PASS; no TypeScript reference to `is_admin`; production build succeeds.

- [ ] **Step 5: Commit**

```bash
git add frontend/package.json frontend/package-lock.json frontend/src/admin frontend/src/components/AdminPanel.tsx frontend/src/api.ts frontend/src/App.tsx frontend/src/styles.css
git commit -m "feat: add role-aware admin workspace"
```

---

### Task 6: Complete Security Regression Suite and Review

**Files:**
- Modify: `backend/tests/Feature/AdminSecurityTest.php`
- Modify: `backend/tests/Feature/AdminAuthorizationTest.php`
- Modify: `backend/tests/Feature/AdminRoleManagementTest.php`
- Create: `docs/SECURITY_REVIEW.md`
- Modify: `README.md`
- Modify: `.env.example`
- Modify: `backend/.env.example`

**Interfaces:**
- Consumes every admin endpoint and role contract from Tasks 1–5.
- Produces a documented threat model, ranked findings and repeatable verification commands.

- [ ] **Step 1: Add missing adversarial tests before any final hardening**

Add table-driven tests with literal expectations:

```php
#[DataProvider('malformedRolePayloads')]
public function test_role_endpoint_rejects_malformed_or_extra_fields(array $payload): void
{
    $owner = User::factory()->create(['admin_role' => User::ROLE_OWNER]);
    $target = User::factory()->create();
    Sanctum::actingAs($owner);
    $this->patchJson("/api/admin/team/{$target->id}", $payload)->assertStatus(422);
    $this->assertSame(User::ROLE_USER, $target->fresh()->admin_role);
}

public static function malformedRolePayloads(): array
{
    return [
        'unknown role' => [['role' => 'superadmin']],
        'mass assignment' => [['role' => 'admin', 'email' => 'attacker@example.com']],
        'nested role' => [['role' => ['owner']]],
        'null role' => [['role' => null]],
    ];
}

public function test_role_changes_have_a_dedicated_rate_limit(): void
{
    $owner = User::factory()->create(['admin_role' => User::ROLE_OWNER]);
    $target = User::factory()->create();
    Sanctum::actingAs($owner);
    for ($i = 0; $i < 5; $i++) {
        $role = $i % 2 === 0 ? 'admin' : 'user';
        $this->patchJson("/api/admin/team/{$target->id}", ['role' => $role])->assertOk();
    }
    $this->patchJson("/api/admin/team/{$target->id}", ['role' => 'admin'])->assertTooManyRequests();
}

public function test_unknown_user_id_does_not_modify_or_disclose_another_account(): void
{
    Sanctum::actingAs(User::factory()->create(['admin_role' => User::ROLE_OWNER]));
    $this->patchJson('/api/admin/team/999999999', ['role' => 'admin'])->assertNotFound();
    $this->assertDatabaseCount('admin_audit_logs', 0);
}
```

Add assertions that error responses omit `trace`, `exception`, SQL fragments, credentials and configured owner email lists.

- [ ] **Step 2: Run adversarial tests and verify RED for uncovered behavior**

Run: `cd backend && php artisan test --filter='AdminSecurityTest|AdminAuthorizationTest|AdminRoleManagementTest'`

Expected: at least the mass-assignment or limiter test fails before the matching hardening. If all pass immediately, identify an uncovered observable boundary from the design before changing production code.

- [ ] **Step 3: Apply only hardening required by the failing tests**

Allowed changes are narrow: strict input-key allowlists, response resource allowlists, correct limiter attachment, sanitized exception rendering, or transaction locking corrections. Do not add new roles, bulk operations, IP allowlists or a permission package.

- [ ] **Step 4: Write the security review document**

`docs/SECURITY_REVIEW.md` contains:

- trust boundaries: browser → Laravel/Sanctum, bot → internal token API, Laravel → PostgreSQL/queue/external stores;
- findings ranked Critical/High/Medium/Low with evidence and status;
- explicit residual risks: bearer-token theft, compromised owner mailbox, dependency vulnerabilities, external API poisoning and absence of external penetration testing;
- commands for backend security tests, full backend suite, frontend tests/lint/build, `composer audit` and `npm audit --omit=dev`;
- statement that this is an engineering review, not a certification.

Update README with role setup: `ADMIN_EMAILS` bootstraps server-managed owners, owners add admins/owners under «Команда», and role changes revoke sessions.

- [ ] **Step 5: Run complete verification**

Run: `cd backend && php artisan test`

Run: `cd backend && composer audit --no-interaction`

Run: `cd frontend && npm test && npm run lint && npm run build && npm audit --omit=dev`

Run: `cd bot && python -m unittest discover -s tests -v`

Run: `docker compose config --quiet`

Expected: all automated tests and builds PASS. Dependency audits must either pass or have each advisory documented with package, severity, exposure analysis and remediation decision.

- [ ] **Step 6: Commit**

```bash
git add backend/tests/Feature/AdminSecurityTest.php backend/tests/Feature/AdminAuthorizationTest.php backend/tests/Feature/AdminRoleManagementTest.php docs/SECURITY_REVIEW.md README.md .env.example backend/.env.example
git commit -m "security: complete admin regression coverage"
```

---

### Task 7: Final Migration and Release Readiness Check

**Files:**
- Modify: `docs/SECURITY_REVIEW.md`

**Interfaces:**
- Verifies the complete system; produces no new feature contract.

- [ ] **Step 1: Test an upgrade-shaped PostgreSQL database**

Create a disposable PostgreSQL database, migrate only through the existing `is_admin` migration, insert one normal user and one legacy admin, then run all remaining migrations. Assert through HTTP that the legacy admin has `admin_role=admin`, a configured `ADMIN_EMAILS` user has effective `owner`, and the normal user receives `403` from `/api/admin/overview`.

- [ ] **Step 2: Verify deployment artifacts**

Run: `docker compose build backend frontend scheduler queue-worker`

Run: `docker compose config --quiet`

Expected: images build successfully; backend startup migration accepts the upgrade-shaped database; scheduler and worker share the same application revision.

- [ ] **Step 3: Inspect changed-file scope and secret exposure**

Run: `git diff --check HEAD~6..HEAD`

Run: `git diff --name-only HEAD~6..HEAD`

Run: `git grep -nE '(password|token|secret|telegram_chat_id)' -- docs/SECURITY_REVIEW.md frontend/src/admin backend/app/Services/Admin`

Expected: every match is a validation, redaction or documented risk; no literal credentials or personal identifiers are present.

- [ ] **Step 4: Record release evidence and commit**

Append a dated «Проверка выпуска» section to `docs/SECURITY_REVIEW.md` containing the exact commands from Steps 1–3, their exit status, and the tested migration range. If a production defect is found, stop this task and return to the earlier task that owns that behavior for a new RED/GREEN cycle before recording successful evidence.

```bash
git add docs/SECURITY_REVIEW.md
git commit -m "docs: record admin security verification"
```
