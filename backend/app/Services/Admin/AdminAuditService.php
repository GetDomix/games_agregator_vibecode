<?php

namespace App\Services\Admin;

use App\Models\AdminAuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
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
        $logs = $this->queryFor($viewer)
            ->latest()
            ->paginate($perPage);

        $logs->setCollection($logs->getCollection()->map(
            fn (AdminAuditLog $log) => $this->toSafeArray($log),
        ));

        return $logs;
    }

    /** @return Collection<int, array<string, mixed>> */
    public function recentFor(?User $viewer, int $limit = 12): Collection
    {
        return $this->queryFor($viewer)
            ->latest()
            ->limit($limit)
            ->get()
            ->map(fn (AdminAuditLog $log) => $this->toSafeArray($log));
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

    private function queryFor(?User $viewer): Builder
    {
        return AdminAuditLog::query()
            ->select(['id', 'request_id', 'actor_id', 'action', 'target_type', 'target_id', 'context', 'created_at'])
            ->with('actor:id,email,display_name,name')
            ->when(
                ! ($viewer?->canManageAdminTeam() ?? false),
                fn ($query) => $query->whereRaw('SUBSTRING(action, 1, 11) <> ?', ['admin.role_']),
            );
    }
}
