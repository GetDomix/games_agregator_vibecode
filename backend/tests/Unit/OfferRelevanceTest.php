<?php

namespace Tests\Unit;

use App\Services\Catalog\OfferRelevance;
use PHPUnit\Framework\TestCase;

class OfferRelevanceTest extends TestCase
{
    public function test_keeps_full_and_plain_name_lots(): void
    {
        $game = 'Alpha: Bravo Charlie';
        $kept = OfferRelevance::filter([
            ['title' => 'Alpha: Bravo Charlie Steam Key'],
            ['title' => 'Alpha Bravo Charlie gift RU'],
        ], $game);

        $this->assertCount(2, $kept);
    }

    public function test_drops_franchise_prefix_only(): void
    {
        $game = 'Alpha: Bravo Charlie';
        $kept = OfferRelevance::filter([
            ['title' => 'Alpha Steam Key GLOBAL'],
            ['title' => 'Alpha: Other Product Steam Gift'],
        ], $game);

        $this->assertSame([], $kept);
    }

    public function test_drops_foreign_digit_discriminator(): void
    {
        $game = 'Alpha Bravo Charlie';
        $kept = OfferRelevance::filter([
            ['title' => 'Alpha Bravo Charlie 1998 Survival Steam Key'],
        ], $game);

        $this->assertSame([], $kept);
    }

    public function test_keeps_distinctive_suffix_abbreviation(): void
    {
        // Unique longest token is the product-specific suffix, not the series prefix.
        $game = 'Alpha Beta Superproduct';
        $kept = OfferRelevance::filter([
            ['title' => 'Superproduct Steam ключ'],
            ['title' => 'Alpha Beta Superproduct аккаунт'],
        ], $game);

        $titles = array_column($kept, 'title');
        $this->assertContains('Superproduct Steam ключ', $titles);
        $this->assertContains('Alpha Beta Superproduct аккаунт', $titles);
    }

    public function test_drops_partial_with_extra_content_words(): void
    {
        $game = 'Alpha Bravo Charlie';
        $kept = OfferRelevance::filter([
            ['title' => 'Alpha Collision Not Found Steam Gift'],
        ], $game);

        $this->assertSame([], $kept);
    }

    public function test_short_multiword_title_requires_all_tokens(): void
    {
        $game = 'Alpha Bravo Charlie';
        $kept = OfferRelevance::filter([
            ['title' => 'Alpha Bravo Steam Key'],       // missing Charlie
            ['title' => 'Alpha Charlie Steam Key'],     // missing Bravo
            ['title' => 'Alpha Bravo Charlie Steam Key'],
        ], $game);

        $this->assertCount(1, $kept);
        $this->assertSame('Alpha Bravo Charlie Steam Key', $kept[0]['title']);
    }

    public function test_two_token_title_requires_both(): void
    {
        $game = 'Alpha Bravo';
        $kept = OfferRelevance::filter([
            ['title' => 'Alpha Steam Key'],
            ['title' => 'Alpha Bravo Steam Key'],
        ], $game);

        $this->assertCount(1, $kept);
        $this->assertSame('Alpha Bravo Steam Key', $kept[0]['title']);
    }

    public function test_long_title_may_drop_one_token(): void
    {
        $game = 'Alpha Bravo Charlie Delta';
        $kept = OfferRelevance::filter([
            ['title' => 'Alpha Bravo Charlie Steam Key'], // 3 of 4
            ['title' => 'Alpha Bravo Steam Key'],         // 2 of 4
        ], $game);

        $titles = array_column($kept, 'title');
        $this->assertContains('Alpha Bravo Charlie Steam Key', $titles);
        $this->assertNotContains('Alpha Bravo Steam Key', $titles);
    }

    public function test_short_name_rejects_sequel_and_extra_content(): void
    {
        $game = 'Hades';
        $kept = OfferRelevance::filter([
            ['title' => 'Hades Steam Key'],
            ['title' => 'Hades II Offline Steam'],
            ['title' => 'Special Costume for Hades'],
        ], $game);

        $titles = array_column($kept, 'title');
        $this->assertSame(['Hades Steam Key'], $titles);
    }
}
