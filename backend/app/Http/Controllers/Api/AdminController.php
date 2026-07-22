<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DailySearchQuota;
use App\Models\PartnerClick;
use App\Models\SearchHistory;
use App\Models\User;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminController extends Controller
{
    public function overview(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);

        $day = now()->utc()->format('Y-m-d');
        $searchesToday = (int) DailySearchQuota::query()->where('day', $day)->sum('count');
        $usersTotal = User::query()->count();
        $proActive = User::query()
            ->whereIn('plan', ['pro', 'unlimited'])
            ->where(function ($q) {
                $q->whereNull('plan_expires_at')->orWhere('plan_expires_at', '>', now());
            })
            ->count();
        $historyTotal = SearchHistory::query()->count();
        $clicks7d = PartnerClick::query()->where('created_at', '>=', now()->subDays(7))->count();
        $clicksByMp = PartnerClick::query()
            ->select('marketplace', DB::raw('count(*) as c'))
            ->where('created_at', '>=', now()->subDays(7))
            ->groupBy('marketplace')
            ->pluck('c', 'marketplace');

        $recentUsers = User::query()
            ->orderByDesc('id')
            ->limit(15)
            ->get()
            ->map(fn (User $u) => [
                'id' => $u->id,
                'email' => $u->email,
                'display_name' => $u->display_name ?: $u->name,
                'plan' => $u->toPublicArray()['plan'],
                'is_admin' => (bool) $u->is_admin,
                'created_at' => $u->created_at?->toIso8601String(),
                'last_login_at' => $u->last_login_at?->toIso8601String(),
            ]);

        return response()->json([
            'stats' => [
                'users_total' => $usersTotal,
                'pro_active' => $proActive,
                'searches_today' => $searchesToday,
                'history_total' => $historyTotal,
                'partner_clicks_7d' => $clicks7d,
                'partner_clicks_by_marketplace' => $clicksByMp,
            ],
            'recent_users' => $recentUsers,
            'promo_codes' => (string) config('gpa.promo_codes', ''),
        ]);
    }

    public function setUserPlan(Request $request, int $id): JsonResponse
    {
        $this->authorizeAdmin($request);
        $data = $request->validate([
            'plan' => ['required', 'in:free,pro,unlimited'],
            'days' => ['nullable', 'integer', 'min:1', 'max:3650'],
        ]);

        $user = User::query()->findOrFail($id);
        $plan = $data['plan'];
        if ($plan === 'free') {
            $user->plan = 'free';
            $user->plan_expires_at = null;
        } else {
            $days = (int) ($data['days'] ?? 30);
            $user->plan = $plan;
            $user->plan_expires_at = now()->addDays($days);
        }
        $user->save();

        return response()->json(['user' => $user->fresh()->toPublicArray()]);
    }

    public function setUserAdmin(Request $request, int $id): JsonResponse
    {
        $this->authorizeAdmin($request);
        $data = $request->validate([
            'is_admin' => ['required', 'boolean'],
        ]);
        $user = User::query()->findOrFail($id);
        if ($user->id === $request->user()->id && ! $data['is_admin']) {
            return response()->json(['detail' => 'Нельзя снять админа с себя'], 422);
        }
        $user->is_admin = (bool) $data['is_admin'];
        $user->save();

        return response()->json(['ok' => true, 'user' => array_merge($user->toPublicArray(), ['is_admin' => $user->is_admin])]);
    }

    private function authorizeAdmin(Request $request): void
    {
        $user = $request->user();
        if (! $user || ! $user->isAdminUser()) {
            throw new HttpResponseException(
                response()->json(['detail' => 'Доступ только для администратора'], 403)
            );
        }
    }
}
