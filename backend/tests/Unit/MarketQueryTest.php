<?php

namespace Tests\Unit;

use App\Services\Catalog\MarketQuery;
use PHPUnit\Framework\TestCase;

class MarketQueryTest extends TestCase
{
    public function test_variants_are_full_title_surface_forms_only(): void
    {
        $variants = MarketQuery::variants('Alpha: Bravo Charlie');
        $this->assertSame(['Alpha: Bravo Charlie', 'Alpha Bravo Charlie'], $variants);
    }

    public function test_variants_do_not_truncate_to_prefix(): void
    {
        $variants = MarketQuery::variants('Series Name: Product Subtitle');
        $this->assertNotContains('Series Name', $variants);
        $this->assertCount(2, $variants);
    }

    public function test_variants_skip_empty(): void
    {
        $this->assertSame([], MarketQuery::variants('   '));
    }

    public function test_search_merged_dedupes_across_variants(): void
    {
        $calls = [];
        $search = function (string $q) use (&$calls): array {
            $calls[] = $q;
            if (str_contains($q, ':')) {
                return [[
                    ['title' => 'Foo: Bar Steam Key', 'url' => 'https://example.com/1', 'price_rub' => 100],
                    ['title' => 'Foo: Bar Gift', 'url' => 'https://example.com/1', 'price_rub' => 100],
                ], 2, null];
            }

            return [[
                ['title' => 'Foo Bar Key', 'url' => 'https://example.com/2', 'price_rub' => 90],
            ], 1, null];
        };

        [$offers, $raw, $err] = MarketQuery::searchMerged($search, 'Foo: Bar');
        $this->assertNull($err);
        $this->assertSame(2, $raw);
        $this->assertCount(2, $offers);
        $this->assertSame(['Foo: Bar', 'Foo Bar'], $calls);
    }

    public function test_search_merged_propagates_error_when_no_offers(): void
    {
        $search = static fn (string $q): array => [[], 0, 'HTTP 503'];
        [$offers, $raw, $err] = MarketQuery::searchMerged($search, 'Alpha');
        $this->assertSame([], $offers);
        $this->assertSame(0, $raw);
        $this->assertSame('HTTP 503', $err);
    }
}
