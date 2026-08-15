<?php

namespace Tests\Feature;

use Tests\TestCase;

class RegionApiTest extends TestCase
{
    public function test_russian_country_header_wins_over_timezone_assumptions(): void
    {
        $this->withHeader('CF-IPCountry', 'RU')->getJson('/api/region')
            ->assertOk()
            ->assertJsonPath('country', 'RU')
            ->assertJsonPath('is_cis', true)
            ->assertJsonPath('locale', 'ru')
            ->assertJsonPath('currency', 'RUB');
    }

    public function test_non_cis_country_defaults_to_english(): void
    {
        $this->withHeader('CF-IPCountry', 'US')->getJson('/api/region')
            ->assertOk()
            ->assertJsonPath('is_cis', false)
            ->assertJsonPath('locale', 'en')
            ->assertJsonPath('currency', 'USD');
    }

    public function test_cis_country_headers_choose_russian_independently_of_timezone(): void
    {
        foreach (['BY', 'KZ', 'KG', 'UZ', 'AM', 'AZ', 'MD', 'TJ', 'TM', 'GE'] as $country) {
            $this->withHeader('CF-IPCountry', $country)->getJson('/api/region')
                ->assertOk()
                ->assertJsonPath('country', $country)
                ->assertJsonPath('is_cis', true)
                ->assertJsonPath('locale', 'ru');
        }
    }
}
