<?php

namespace App\Console\Commands;

use App\Services\RadarScanService;
use Illuminate\Console\Command;

class RadarScanCommand extends Command
{
    protected $signature = 'radar:scan';

    protected $description = 'Scan favorites Steam prices and notify Telegram users (Price Radar)';

    public function handle(RadarScanService $radar): int
    {
        $this->info('Radar scan starting...');
        $stats = $radar->run();
        $this->info(sprintf(
            'done scanned=%d events=%d notified=%d errors=%d',
            $stats['scanned'],
            $stats['events'],
            $stats['notified'],
            $stats['errors'],
        ));

        return self::SUCCESS;
    }
}
