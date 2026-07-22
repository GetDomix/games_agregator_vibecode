<?php

namespace Tests\Unit;

use App\Services\DealScoreService;
use PHPUnit\Framework\TestCase;

class DealScoreTest extends TestCase
{
    public function test_better_deal_scores_high(): void
    {
        $plati = [
            'by_kind' => [
                ['min_price' => 200],
            ],
            'error' => null,
        ];
        $ggsel = ['by_kind' => [], 'error' => null];
        $deal = DealScoreService::compute(1000.0, $plati, $ggsel);
        $this->assertTrue($deal['is_better']);
        $this->assertGreaterThanOrEqual(80, $deal['score']);
        $this->assertSame(200.0, $deal['market_min_rub']);
    }

    public function test_no_steam_price(): void
    {
        $deal = DealScoreService::compute(null, [
            'by_kind' => [['min_price' => 100]],
            'error' => null,
        ], ['by_kind' => [], 'error' => null]);
        $this->assertFalse($deal['is_better']);
        $this->assertSame(0, $deal['score']);
    }
}
