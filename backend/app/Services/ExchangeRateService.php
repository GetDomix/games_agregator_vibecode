<?php

namespace App\Services;

use App\Models\ExchangeRate;
use Illuminate\Support\Facades\Log;

class ExchangeRateService
{
    public function refresh(): int
    {
        $currencies = array_unique(array_values(array_column(config('gpa.steam_price_regions'), 'currency')));
        $currencies = array_values(array_filter($currencies, fn (string $currency) => $currency !== 'RUB'));
        if ($currencies === []) {
            return 0;
        }

        $response = HttpClientFactory::make()->get('https://www.cbr.ru/scripts/XML_daily.asp');
        if (! $response->successful()) {
            throw new \RuntimeException('CBR exchange-rate endpoint returned HTTP '.$response->status());
        }
        $xml = @simplexml_load_string($response->body());
        if ($xml === false) {
            throw new \RuntimeException('CBR exchange-rate endpoint returned invalid XML');
        }

        $updated = 0;
        foreach ($xml->Valute as $item) {
            $currency = (string) $item->CharCode;
            if (! in_array($currency, $currencies, true)) {
                continue;
            }
            $nominal = (float) str_replace(',', '.', (string) $item->Nominal);
            $value = (float) str_replace(',', '.', (string) $item->Value);
            if ($nominal <= 0 || $value < 0) {
                continue;
            }
            ExchangeRate::query()->updateOrCreate(['currency' => $currency], [
                'rub_per_unit' => round($value / $nominal, 6),
                'observed_at' => now(),
            ]);
            $updated++;
        }
        if ($updated !== count($currencies)) {
            Log::warning('exchange_rates_incomplete', ['requested' => $currencies, 'updated' => $updated]);
        }

        return $updated;
    }

    public function rubFor(string $currency, float $amount): ?float
    {
        if ($currency === 'RUB') {
            return round($amount, 2);
        }
        $rate = ExchangeRate::query()->where('currency', $currency)->first();
        if (! $rate || ! $rate->observed_at || $rate->observed_at->lt(now()->subHours(30))) {
            $this->refresh();
            $rate = ExchangeRate::query()->where('currency', $currency)->first();
        }

        return $rate ? round($amount * $rate->rub_per_unit, 2) : null;
    }
}
