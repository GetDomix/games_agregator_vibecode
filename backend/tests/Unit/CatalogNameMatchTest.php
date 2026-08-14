<?php

namespace Tests\Unit;

use App\Services\Catalog\CatalogNameMatch;
use PHPUnit\Framework\TestCase;

class CatalogNameMatchTest extends TestCase
{
    public function test_picks_exact_catalog_game(): void
    {
        $entities = [
            ['name' => 'Alpha II', 'id_cb' => 2],
            ['name' => 'Alpha', 'id_cb' => 1],
        ];
        $picked = CatalogNameMatch::pick($entities, 'Alpha');
        $this->assertNotNull($picked);
        $this->assertSame(1, $picked['id_cb']);
    }

    public function test_picks_sequel_when_steam_is_sequel(): void
    {
        $entities = [
            ['name' => 'Alpha', 'id_cb' => 1],
            ['name' => 'Alpha II', 'id_cb' => 2],
        ];
        $picked = CatalogNameMatch::pick($entities, 'Alpha II');
        $this->assertNotNull($picked);
        $this->assertSame(2, $picked['id_cb']);
    }

    public function test_returns_null_when_no_catalog_card(): void
    {
        $entities = [
            ['name' => 'Escape the Alpha', 'id_cb' => 9],
        ];
        $this->assertNull(CatalogNameMatch::pick($entities, 'Alpha: Bravo Charlie'));
    }

    public function test_picks_expanded_legacy_catalog_alias_without_hardcoding_the_game(): void
    {
        $entities = [
            ['name' => 'Counter-Strike: Global Offensive (CS 2)', 'id_cb' => 277],
            ['name' => 'Counter-Strike 3', 'id_cb' => 999],
        ];

        $picked = CatalogNameMatch::pick($entities, 'Counter-Strike 2');

        $this->assertNotNull($picked);
        $this->assertSame(277, $picked['id_cb']);
    }

    public function test_ordered_alias_rejects_foreign_sequel_numbers(): void
    {
        $this->assertNull(CatalogNameMatch::pick([
            ['name' => 'Counter Strike Global 3 Deluxe', 'id_cb' => 3],
        ], 'Counter Strike'));
    }

    public function test_latin_diacritics_do_not_hide_the_same_catalog_game(): void
    {
        $picked = CatalogNameMatch::pick([
            ['name' => 'God of War Ragnarok', 'url' => 'god-of-war-ragnarok'],
        ], 'God of War Ragnarök');

        $this->assertSame('god-of-war-ragnarok', $picked['url'] ?? null);
    }

    public function test_equivalent_roman_and_arabic_sequel_numbers_match_without_crossing_sequels(): void
    {
        $picked = CatalogNameMatch::pick([
            ['name' => 'Grand Theft Auto 6', 'url' => 'gta-6'],
            ['name' => 'Grand Theft Auto 5', 'url' => 'gta-5'],
        ], 'Grand Theft Auto V');

        $this->assertSame('gta-5', $picked['url'] ?? null);
        $this->assertNull(CatalogNameMatch::pick([
            ['name' => 'Hades III', 'url' => 'hades-3'],
        ], 'Hades II'));
    }

    public function test_lot_belongs_to_category_prefers_longer_sibling(): void
    {
        $cats = ['Alpha', 'Alpha II', 'Alpha ключи Steam'];
        $this->assertTrue(CatalogNameMatch::lotBelongsToCategory('Alpha Steam Key', 'Alpha', $cats));
        $this->assertFalse(CatalogNameMatch::lotBelongsToCategory('Alpha II Steam Key', 'Alpha', $cats));
        $this->assertTrue(CatalogNameMatch::lotBelongsToCategory('Alpha II Steam Key', 'Alpha II', $cats));
        // sub-chip still under the base game card
        $this->assertTrue(CatalogNameMatch::lotBelongsToCategory('Alpha ключи Steam RU', 'Alpha', $cats));
    }

    public function test_prefix_only_franchise_detector(): void
    {
        $this->assertTrue(CatalogNameMatch::isPrefixOnlyFranchise('hades', 'hades ii'));
        $this->assertTrue(CatalogNameMatch::isPrefixOnlyFranchise('hades', 'hades 2'));
        $this->assertFalse(CatalogNameMatch::isPrefixOnlyFranchise('hades', 'hades'));
        $this->assertFalse(CatalogNameMatch::isPrefixOnlyFranchise('hollow knight silksong', 'hollow knight silksong'));
    }
}
