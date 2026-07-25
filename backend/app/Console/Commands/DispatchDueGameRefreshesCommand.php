<?php

namespace App\Console\Commands;

use App\Services\DueGameRefreshDispatcher;
use Illuminate\Console\Command;

class DispatchDueGameRefreshesCommand extends Command
{
    protected $signature = 'prices:dispatch-due';

    protected $description = 'Dispatch due canonical game price refresh jobs';

    public function handle(DueGameRefreshDispatcher $dispatcher): int
    {
        $count = $dispatcher->dispatch();
        $this->info("Dispatched {$count} price refresh jobs.");

        return self::SUCCESS;
    }
}
