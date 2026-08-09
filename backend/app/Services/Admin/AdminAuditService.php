<?php

namespace App\Services\Admin;

use App\Models\AdminAuditLog;
use App\Models\User;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class AdminAuditService
{
    private const CONTEXT_KEYS = [
        'admin.role_changed' => ['old_role', 'new_role'],
        'game.refresh_requested' => ['sources'],
    ];

    public function record(
        User $actor,
        string $action,
        string $targetType,
        string $targetId,
        array $context = [],
    ): AdminAuditLog {
        return AdminAuditLog::query()->create([
            'request_id' => Str::uuid()->toString(),
            'actor_id' => $actor->id,
            'action' => $action,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'context' => Arr::only($context, self::CONTEXT_KEYS[$action] ?? []),
        ]);
    }

    public function paginateFor(User $viewer, int $perPage = 25): LengthAwarePaginator
    {
        $logs = AdminAuditLog::query()
            ->select(['id', 'request_id', 'actor_id', 'action', 'target_type', 'target_id', 'context', 'created_at'])
            ->with('actor:id,email,display_name,name')
            ->when(
                ! $viewer->canManageAdminTeam(),
                fn ($query) => $query->whereRaw('SUBSTRING(action, 1, 11) <> ?', ['admin.role_']),
            )
            ->latest()
            ->paginate($perPage);

        $logs->setCollection($logs->getCollection()->map(
            fn (AdminAuditLog $log) => $this->toSafeArray($log),
        ));

        return $logs;
    }

    public function toSafeArray(AdminAuditLog $log): array
    {
        return [
            'id' => $log->id,
            'request_id' => $log->request_id,
            'actor' => $log->actor?->display_name ?: $log->actor?->name ?: $log->actor?->email,
            'action' => $log->action,
            'target_type' => $log->target_type,
            'target_id' => $log->target_id,
            'context' => Arr::only($log->context ?? [], self::CONTEXT_KEYS[$log->action] ?? []),
            'created_at' => $log->created_at?->toIso8601String(),
        ];
    }
}
