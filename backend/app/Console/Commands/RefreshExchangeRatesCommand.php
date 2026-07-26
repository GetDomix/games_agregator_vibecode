<?php

namespace App\Console\Commands;

use App\Services\ExchangeRateService;
use Illuminate\Console\Command;

class RefreshExchangeRatesCommand extends Command
{
    protected $signature = 'prices:refresh-rates';

    protected $description = 'Refresh regional currency rates used for Steam price conversion';

    public function handle(ExchangeRateService $rates): int
    {
        $count = $rates->refresh();
        $this->info("Refreshed {$count} exchange rates.");

        return self::SUCCESS;
    }
}
