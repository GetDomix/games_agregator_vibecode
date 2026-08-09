<?php

namespace App\Services\Admin;

use App\Models\AdminAuditLog;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class AdminAuditService
{
    private const CONTEXT_KEYS = [
        'admin.role_changed' => ['old_role', 'new_role'],
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
}
