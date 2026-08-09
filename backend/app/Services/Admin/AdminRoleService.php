<?php

namespace App\Services\Admin;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AdminRoleService
{
    public function __construct(private readonly AdminAuditService $audit) {}

    public function changeRole(User $actor, User $target, string $newRole, ?string $currentPassword): User
    {
        return DB::transaction(function () use ($actor, $target, $newRole, $currentPassword) {
            $lockedTarget = User::query()->lockForUpdate()->findOrFail($target->id);
            $lockedOwners = $this->lockEffectiveOwners();
            $lockedActor = $lockedOwners->firstWhere('id', $actor->id);

            if (! $lockedActor?->canManageAdminTeam()) {
                throw new AuthorizationException('Доступ только для владельца');
            }
            if ($lockedTarget->isServerManagedOwner()) {
                throw ValidationException::withMessages([
                    'role' => ['Этот владелец управляется серверной конфигурацией'],
                ]);
            }

            $oldRole = $lockedTarget->effectiveAdminRole();
            if ($oldRole === User::ROLE_OWNER || $newRole === User::ROLE_OWNER) {
                $this->verifyPassword($lockedActor, $currentPassword);
            }

            $this->guardLastOwner($lockedOwners, $lockedTarget, $newRole);

            $lockedTarget->forceFill(['admin_role' => $newRole])->save();
            $lockedTarget->tokens()->delete();
            $this->audit->record(
                $lockedActor,
                'admin.role_changed',
                'user',
                (string) $lockedTarget->id,
                ['old_role' => $oldRole, 'new_role' => $newRole],
            );

            return $lockedTarget->refresh();
        }, attempts: 3);
    }

    /** @return Collection<int, User> */
    private function lockEffectiveOwners(): Collection
    {
        $configuredEmails = $this->configuredOwnerEmails();

        return User::query()
            ->where(function ($query) use ($configuredEmails) {
                $query->where('admin_role', User::ROLE_OWNER);
                if ($configuredEmails !== []) {
                    $query->orWhereIn(DB::raw('LOWER(email)'), $configuredEmails);
                }
            })
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
    }

    /** @return list<string> */
    private function configuredOwnerEmails(): array
    {
        return array_values(array_unique(array_filter(array_map(
            fn (string $email) => mb_strtolower(trim($email)),
            explode(',', (string) config('gpa.admin_emails', '')),
        ))));
    }

    private function verifyPassword(User $actor, ?string $currentPassword): void
    {
        if ($currentPassword === null || ! Hash::check($currentPassword, $actor->password)) {
            throw ValidationException::withMessages([
                'current_password' => ['Текущий пароль указан неверно'],
            ]);
        }
    }

    /** @param Collection<int, User> $lockedOwners */
    private function guardLastOwner(Collection $lockedOwners, User $target, string $newRole): void
    {
        if ($target->effectiveAdminRole() !== User::ROLE_OWNER || $newRole === User::ROLE_OWNER) {
            return;
        }

        $hasAnotherOwner = $lockedOwners->contains(
            fn (User $owner) => $owner->id !== $target->id && $owner->canManageAdminTeam(),
        );
        if (! $hasAnotherOwner) {
            throw ValidationException::withMessages([
                'role' => ['Нельзя снять роль у последнего владельца'],
            ]);
        }
    }
}
