<?php

namespace Tests\Unit;

use App\Services\Catalog\SearchCandidateRanker;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class SearchCandidateRankerTest extends TestCase
{
    #[Test]
    public function it_ranks_normalized_exact_prefix_phrase_and_token_matches_in_that_order(): void
    {
        $ranked = (new SearchCandidateRanker)->rank([
            ['appid' => 1, 'name' => 'Game Alpha Collection', 'candidate_kind' => 'game'],
            ['appid' => 2, 'name' => 'Deluxe Alpha Game Pack', 'candidate_kind' => 'game'],
            ['appid' => 3, 'name' => 'Alpha Game Extended', 'candidate_kind' => 'game'],
            ['appid' => 4, 'name' => '  ALPHA—GAME  ', 'candidate_kind' => 'game'],
        ], 'alpha game', 20);

        $this->assertSame([4, 3, 2, 1], array_column($ranked, 'appid'));
    }

    #[Test]
    public function it_prefers_a_base_game_to_secondary_content_at_equal_text_relevance(): void
    {
        $ranked = (new SearchCandidateRanker)->rank([
            ['appid' => 10, 'name' => 'Alpha Demo', 'candidate_kind' => 'demo'],
            ['appid' => 11, 'name' => 'Alpha Soundtrack', 'candidate_kind' => 'soundtrack'],
            ['appid' => 12, 'name' => 'Alpha Prime', 'candidate_kind' => 'game'],
            ['appid' => 13, 'name' => 'Alpha Complete Edition', 'candidate_kind' => 'edition'],
        ], 'alpha', 20);

        $this->assertSame([12, 13, 10, 11], array_column($ranked, 'appid'));
    }
}
