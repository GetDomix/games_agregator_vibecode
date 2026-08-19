<?php

namespace Tests\Unit;

use App\Services\Catalog\SteamOfferEligibility;
use PHPUnit\Framework\TestCase;

class SteamOfferEligibilityTest extends TestCase
{
    public function test_it_removes_explicit_console_offers_from_a_marketplace_game_card(): void
    {
        $offers = SteamOfferEligibility::filter([
            ['title' => 'Cyberpunk 2077 Steam Gift RU'],
            ['title' => 'Cyberpunk 2077 аккаунт'],
            ['title' => 'Cyberpunk 2077 Xbox One|Series XS ключ'],
            ['title' => 'Cyberpunk 2077 PS4/PS5 аренда'],
            ['title' => 'Cyberpunk 2077 Nintendo Switch'],
            ['title' => 'Cyberpunk 2077 PlayStation 5'],
            ['title' => 'Cyberpunk 2077 GOG.com key'],
            ['title' => 'Cyberpunk 2077 Epic Games account'],
            ['title' => 'Cyberpunk 2077 Microsoft Store key'],
            ['title' => 'Cyberpunk 2077 GFN / Play Key / My Games Cloud'],
        ], 'Cyberpunk 2077');

        $this->assertSame([
            'Cyberpunk 2077 Steam Gift RU',
            'Cyberpunk 2077 аккаунт',
        ], array_column($offers, 'title'));
    }

    public function test_platform_word_inside_the_canonical_game_name_is_not_a_store_tag(): void
    {
        $offers = SteamOfferEligibility::filter([
            ['title' => 'Disney Epic Mickey: Rebrushed Steam Key'],
        ], 'Disney Epic Mickey: Rebrushed');

        $this->assertCount(1, $offers);
    }
}
