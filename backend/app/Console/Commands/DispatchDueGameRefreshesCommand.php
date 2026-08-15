<?php

namespace App\Console\Commands;

use App\Services\Pricing\DueGameRefreshDispatcher;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class DispatchDueGameRefreshesCommand extends Command
{
    protected $signature = 'prices:dispatch-due';

    protected $description = 'Dispatch due canonical game price refresh jobs';

    public function handle(DueGameRefreshDispatcher $dispatcher): int
    {
        $count = $dispatcher->dispatch();
        $this->info("Dispatched {$count} price refresh jobs.");
        Log::info('price_refresh_dispatch_completed', ['jobs_dispatched' => $count]);

        return self::SUCCESS;
    }
}
