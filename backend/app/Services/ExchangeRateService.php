<?php

namespace App\Services;

use App\Models\ExchangeRate;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ExchangeRateService
{
    /** @return array<string, float> */
    public function availableRates(): array
    {
        $currencies = array_values(array_unique((array) config('gpa.display_currencies', ['RUB'])));
        $foreign = array_values(array_diff($currencies, ['RUB']));
        $fresh = ExchangeRate::query()
            ->whereIn('currency', $foreign)
            ->where('observed_at', '>=', now()->subHours(30))
            ->pluck('currency')
            ->all();

        if (count($fresh) !== count($foreign)) {
            Cache::remember('exchange_rates_refresh_attempt', now()->addMinutes(10), function (): bool {
                try {
                    $this->refresh();
                } catch (\Throwable $exception) {
                    // A temporary CBR outage must not disable currencies that were loaded before.
                    Log::warning('exchange_rates_refresh_failed', ['message' => $exception->getMessage()]);
                }

                return true;
            });
        }

        $rates = ['RUB' => 1.0];

        ExchangeRate::query()
            ->whereIn('currency', $foreign)
            ->get()
            ->each(function (ExchangeRate $rate) use (&$rates): void {
                $rates[$rate->currency] = (float) $rate->rub_per_unit;
            });

        return array_filter(
            array_replace(array_fill_keys($currencies, null), $rates),
            static fn ($rate): bool => is_float($rate) && $rate > 0,
        );
    }

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
