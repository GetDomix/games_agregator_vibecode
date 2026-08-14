<?php

namespace Tests\Unit;

use App\Services\Catalog\GameCandidateKindClassifier;
use PHPUnit\Framework\TestCase;

class GameCandidateKindClassifierTest extends TestCase
{
    public function test_it_labels_only_conservative_name_variants(): void
    {
        $classifier = new GameCandidateKindClassifier();

        $this->assertSame('demo', $classifier->classify('Hades Demo'));
        $this->assertSame('soundtrack', $classifier->classify('Hades Original Soundtrack'));
        $this->assertSame('dlc', $classifier->classify('Hades DLC'));
        $this->assertSame('remaster', $classifier->classify('System Shock Remaster'));
        $this->assertSame('edition', $classifier->classify('Control Ultimate Edition'));
        $this->assertSame('game', $classifier->classify('Control'));
    }
}
