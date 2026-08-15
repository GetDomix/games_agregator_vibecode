<?php

namespace App\Services\Admin;

use App\Models\User;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class AdminUserDirectoryService
{
    public function search(string $term, int $perPage = 30): LengthAwarePaginator
    {
        $term = trim($term);
        $users = User::query()
            ->select([
                'id',
                'email',
                'display_name',
                'name',
                'admin_role',
                'radar_enabled',
                'alert_prefs',
                'created_at',
                'last_login_at',
                DB::raw('(telegram_chat_id IS NOT NULL) AS telegram_linked'),
            ])
            ->withCount(['favorites', 'searchHistories'])
            ->when($term !== '', function ($query) use ($term) {
                $literal = addcslashes(mb_strtolower($term), '\\%_');
                $pattern = "%{$literal}%";
                $query->where(function ($match) use ($pattern, $term) {
                    $match->whereRaw("LOWER(email) LIKE ? ESCAPE E'\\\\'", [$pattern])
                        ->orWhereRaw("LOWER(display_name) LIKE ? ESCAPE E'\\\\'", [$pattern])
                        ->orWhereRaw("LOWER(name) LIKE ? ESCAPE E'\\\\'", [$pattern]);
                    if (ctype_digit($term)) {
                        $match->orWhere('id', (int) $term);
                    }
                });
            })
            ->latest('id')
            ->paginate($perPage);

        $users->setCollection($users->getCollection()->map(fn (User $user) => [
            'id' => $user->id,
            'email' => $user->email,
            'display_name' => $user->display_name ?: $user->name,
            'admin_role' => $user->effectiveAdminRole(),
            'can_access_admin' => $user->canAccessAdmin(),
            'can_manage_admin_team' => $user->canManageAdminTeam(),
            'telegram_linked' => (bool) $user->telegram_linked,
            'radar_enabled' => (bool) ($user->radar_enabled ?? true),
            'alert_prefs' => $user->alert_prefs,
            'created_at' => $user->created_at?->toIso8601String(),
            'last_login_at' => $user->last_login_at?->toIso8601String(),
            'favorites_count' => (int) $user->favorites_count,
            'searches_count' => (int) $user->search_histories_count,
        ]));

        return $users;
    }
}
