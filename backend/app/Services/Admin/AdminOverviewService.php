<?php

namespace App\Services\Admin;

use App\Models\AdminAuditLog;
use App\Models\AlertDelivery;
use App\Models\AlertEvent;
use App\Models\Game;
use App\Models\GameSourceState;
use App\Models\PartnerClick;
use App\Models\SearchHistory;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class AdminOverviewService
{
    public function __construct(private readonly AdminAuditService $audit) {}

    public function build(): array
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

        return [
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
                ->select(['id', 'request_id', 'actor_id', 'action', 'target_type', 'target_id', 'context', 'created_at'])
                ->with('actor:id,email,display_name,name')
                ->latest()
                ->limit(12)
                ->get()
                ->map(fn (AdminAuditLog $log) => $this->audit->toSafeArray($log)),
        ];
    }
}
