<?php

namespace App\Console\Commands;

use App\Models\AlertDelivery;
use App\Models\AlertEvent;
use App\Models\GameSourceState;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class OperationsSnapshotCommand extends Command
{
    protected $signature = 'ops:snapshot {--hours=24 : Lookback window in hours}';

    protected $description = 'Emit an aggregate operational snapshot without personal data';

    public function handle(): int
    {
        $hours = filter_var($this->option('hours'), FILTER_VALIDATE_INT);
        if ($hours === false || $hours < 1 || $hours > 24 * 31) {
            $this->error('The --hours option must be an integer between 1 and 744.');

            return self::FAILURE;
        }

        $since = now()->subHours($hours);
        $sources = [];
        foreach (GameSourceState::SOURCES as $source) {
            $sources[$source] = array_fill_keys(GameSourceState::STATUSES, 0);
        }
        $stateCounts = GameSourceState::query()
            ->select(['source', 'status', DB::raw('COUNT(*) AS aggregate')])
            ->groupBy('source', 'status')
            ->get();
        foreach ($stateCounts as $row) {
            $sources[$row->source][$row->status] = (int) $row->aggregate;
        }

        $deliveryCounts = array_fill_keys([
            AlertDelivery::STATUS_PENDING,
            AlertDelivery::STATUS_SENT,
            AlertDelivery::STATUS_FAILED,
            AlertDelivery::STATUS_SKIPPED,
        ], 0);
        foreach (AlertDelivery::query()
            ->where('updated_at', '>=', $since)
            ->select(['status', DB::raw('COUNT(*) AS aggregate')])
            ->groupBy('status')
            ->get() as $row) {
            $deliveryCounts[$row->status] = (int) $row->aggregate;
        }

        $snapshot = [
            'generated_at' => now()->toIso8601String(),
            'window_hours' => $hours,
            'refreshed_games' => GameSourceState::query()
                ->where('last_attempt_at', '>=', $since)
                ->distinct('game_id')
                ->count('game_id'),
            'source_states' => $sources,
            'queue' => [
                'pending' => DB::table('jobs')->count(),
                'failed' => DB::table('failed_jobs')->count(),
            ],
            'alert_events_created' => AlertEvent::query()->where('created_at', '>=', $since)->count(),
            'deliveries' => $deliveryCounts,
        ];

        Log::info('operations_snapshot', $snapshot);
        $this->line(json_encode($snapshot, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
