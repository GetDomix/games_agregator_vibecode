<?php

namespace Tests\Feature;

use App\Models\ExchangeRate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CurrencyApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_keeps_last_known_rates_when_refresh_is_temporarily_unavailable(): void
    {
        Http::fake(['www.cbr.ru/*' => Http::response('', 503)]);

        ExchangeRate::query()->create([
            'currency' => 'USD',
            'rub_per_unit' => 80.5,
            'observed_at' => now(),
        ]);
        ExchangeRate::query()->create([
            'currency' => 'EUR',
            'rub_per_unit' => 92.25,
            'observed_at' => now()->subHours(31),
        ]);

        $this->getJson('/api/currencies')
            ->assertOk()
            ->assertJsonPath('base', 'RUB')
            ->assertJsonPath('rates.RUB', 1)
            ->assertJsonPath('rates.USD', 80.5)
            ->assertJsonPath('rates.EUR', 92.25);
    }

    public function test_it_loads_all_display_rates_from_one_cbr_response(): void
    {
        Http::fake(['www.cbr.ru/*' => Http::response('<?xml version="1.0"?><ValCurs>
            <Valute><CharCode>USD</CharCode><Nominal>1</Nominal><Value>80,50</Value></Valute>
            <Valute><CharCode>EUR</CharCode><Nominal>1</Nominal><Value>92,25</Value></Valute>
            <Valute><CharCode>KZT</CharCode><Nominal>100</Nominal><Value>17,50</Value></Valute>
            <Valute><CharCode>TRY</CharCode><Nominal>10</Nominal><Value>18,00</Value></Valute>
        </ValCurs>', 200)]);

        $this->getJson('/api/currencies')
            ->assertOk()
            ->assertJsonPath('rates.USD', 80.5)
            ->assertJsonPath('rates.EUR', 92.25)
            ->assertJsonPath('rates.KZT', 0.175)
            ->assertJsonPath('rates.TRY', 1.8);
    }
}
