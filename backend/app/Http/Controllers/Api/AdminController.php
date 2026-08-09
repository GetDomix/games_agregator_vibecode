<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AdminAuditLog;
use App\Models\AlertDelivery;
use App\Models\AlertEvent;
use App\Models\Game;
use App\Models\GameSourceState;
use App\Models\PartnerClick;
use App\Models\SearchHistory;
use App\Models\User;
use App\Services\GameRefreshRequestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AdminController extends Controller
{
    public function overview(Request $request): JsonResponse
    {
        $since = now()->subDay();

        $sourceCounts = GameSourceState::query()
            ->select(['source', 'status', DB::raw('COUNT(*) AS aggregate')])
            ->groupBy('source', 'status')
            ->get()
            ->groupBy('source');
        $latestSourceSuccess = GameSourceState::query()
            ->select(['source', DB::raw('MAX(last_success_at) AS last_success_at')])
            ->groupBy('source')
            ->pluck('last_success_at', 'source');
        $sources = collect(GameSourceState::SOURCES)->map(function (string $source) use ($sourceCounts, $latestSourceSuccess) {
            $counts = array_fill_keys(GameSourceState::STATUSES, 0);
            foreach ($sourceCounts->get($source, collect()) as $row) {
                $counts[$row->status] = (int) $row->aggregate;
            }

            return [
                'source' => $source,
                'counts' => $counts,
                'last_success_at' => $latestSourceSuccess[$source] ?? null,
            ];
        })->values();

        $deliveries = array_fill_keys([
            AlertDelivery::STATUS_PENDING,
            AlertDelivery::STATUS_SENT,
            AlertDelivery::STATUS_FAILED,
            AlertDelivery::STATUS_SKIPPED,
        ], 0);
        foreach (AlertDelivery::query()
            ->where('updated_at', '>=', $since)
            ->select(['status', DB::raw('COUNT(*) AS aggregate')])
            ->groupBy('status')->get() as $row) {
            $deliveries[$row->status] = (int) $row->aggregate;
        }

        $problemSearches = SearchHistory::query()
            ->where(function ($query) {
                $query->whereNull('appid')->orWhere(function ($prices) {
                    $prices->whereNull('steam_price_rub')
                        ->whereNull('plati_min_rub')
                        ->whereNull('ggsel_min_rub');
                });
            })
            ->select(['query', DB::raw('COUNT(*) AS searches'), DB::raw('MAX(created_at) AS last_seen_at')])
            ->groupBy('query')
            ->orderByDesc('searches')
            ->limit(10)
            ->get();

        $popularSearches = SearchHistory::query()
            ->where('created_at', '>=', now()->subDays(7))
            ->select(['query', DB::raw('COUNT(*) AS searches')])
            ->groupBy('query')
            ->orderByDesc('searches')
            ->limit(10)
            ->get();

        return response()->json([
            'generated_at' => now()->toIso8601String(),
            'stats' => [
                'users_total' => User::query()->count(),
                'users_7d' => User::query()->where('created_at', '>=', now()->subDays(7))->count(),
                'games_total' => Game::query()->count(),
                'searches_24h' => SearchHistory::query()->where('created_at', '>=', $since)->count(),
                'partner_clicks_7d' => PartnerClick::query()->where('created_at', '>=', now()->subDays(7))->count(),
                'alert_events_24h' => AlertEvent::query()->where('created_at', '>=', $since)->count(),
            ],
            'operations' => [
                'queue' => [
                    'pending' => DB::table('jobs')->count(),
                    'failed' => DB::table('failed_jobs')->count(),
                ],
                'sources' => $sources,
                'deliveries_24h' => $deliveries,
            ],
            'recent_source_failures' => GameSourceState::query()
                ->with('game:id,steam_appid,name')
                ->where('status', GameSourceState::STATUS_FAILED)
                ->orderByDesc('last_attempt_at')
                ->limit(10)
                ->get()
                ->map(fn (GameSourceState $state) => [
                    'appid' => $state->game?->steam_appid,
                    'game_name' => $state->game?->name,
                    'source' => $state->source,
                    'last_attempt_at' => $state->last_attempt_at?->toIso8601String(),
                    'consecutive_failures' => (int) $state->consecutive_failures,
                    'error' => mb_substr((string) $state->last_error, 0, 300),
                ]),
            'popular_searches_7d' => $popularSearches,
            'problem_searches' => $problemSearches,
            'recent_audit' => AdminAuditLog::query()
                ->with('actor:id,email,display_name,name')
                ->latest()
                ->limit(12)
                ->get()
                ->map(fn (AdminAuditLog $log) => [
                    'id' => $log->id,
                    'actor' => $log->actor?->display_name ?: $log->actor?->name ?: $log->actor?->email,
                    'action' => $log->action,
                    'target_type' => $log->target_type,
                    'target_id' => $log->target_id,
                    'context' => $log->context,
                    'created_at' => $log->created_at?->toIso8601String(),
                ]),
        ]);
    }

    public function users(Request $request): JsonResponse
    {
        $data = $request->validate(['q' => ['nullable', 'string', 'max:120']]);
        $term = trim((string) ($data['q'] ?? ''));
        $users = User::query()
            ->withCount(['favorites', 'searchHistories'])
            ->when($term !== '', function ($query) use ($term) {
                $needle = '%'.mb_strtolower($term).'%';
                $query->where(function ($match) use ($needle, $term) {
                    $match->whereRaw('LOWER(email) LIKE ?', [$needle])
                        ->orWhereRaw('LOWER(display_name) LIKE ?', [$needle])
                        ->orWhereRaw('LOWER(name) LIKE ?', [$needle]);
                    if (ctype_digit($term)) {
                        $match->orWhere('id', (int) $term);
                    }
                });
            })
            ->latest('id')
            ->limit(30)
            ->get()
            ->map(fn (User $user) => [
                ...$user->toPublicArray(),
                'favorites_count' => (int) $user->favorites_count,
                'searches_count' => (int) $user->search_histories_count,
                'telegram_linked' => (bool) $user->telegram_chat_id,
            ]);

        return response()->json(['items' => $users]);
    }

    public function setUserAdmin(Request $request, int $id): JsonResponse
    {
        // Transitional compatibility for the existing boolean client contract; Task 3 replaces it with role input.
        $data = $request->validate(['is_admin' => ['required', 'boolean']]);
        $user = User::query()->findOrFail($id);
        if ($user->id === $request->user()->id && ! $data['is_admin']) {
            return response()->json(['detail' => 'Нельзя снять админа с себя'], 422);
        }
        $user->admin_role = $data['is_admin'] ? User::ROLE_ADMIN : User::ROLE_USER;
        $user->save();
        $this->audit($request, 'user.admin_changed', 'user', (string) $user->id, ['admin_role' => $user->admin_role]);

        return response()->json(['ok' => true, 'user' => $user->toPublicArray()]);
    }

    public function refreshGame(Request $request, int $appid, GameRefreshRequestService $refreshes): JsonResponse
    {
        $data = $request->validate([
            'sources' => ['nullable', 'array', 'min:1'],
            'sources.*' => ['string', Rule::in(GameSourceState::SOURCES)],
        ]);
        $game = Game::query()->where('steam_appid', $appid)->firstOrFail();
        $sources = array_values(array_unique($data['sources'] ?? GameSourceState::SOURCES));
        $refreshes->request($game, $sources);
        $this->audit($request, 'game.refresh_requested', 'game', (string) $appid, ['sources' => $sources]);

        return response()->json(['ok' => true, 'appid' => $appid, 'sources' => $sources], 202);
    }

    private function audit(Request $request, string $action, ?string $targetType, ?string $targetId, array $context = []): void
    {
        AdminAuditLog::query()->create([
            'actor_id' => $request->user()?->id,
            'action' => $action,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'context' => $context,
        ]);
    }

}
