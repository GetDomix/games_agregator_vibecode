<?php

namespace App\Console\Commands;

use App\Models\GamePriceObservation;
use Illuminate\Console\Command;

class PruneGamePriceHistoryCommand extends Command
{
    protected $signature = 'prices:prune-history';

    protected $description = 'Delete expired canonical price observations';

    public function handle(): int
    {
        $deleted = GamePriceObservation::query()
            ->where('observed_at', '<', now()->subDays(GamePriceObservation::RETENTION_DAYS))
            ->delete();
        $this->info("Deleted {$deleted} expired observations.");

        return self::SUCCESS;
    }
}
