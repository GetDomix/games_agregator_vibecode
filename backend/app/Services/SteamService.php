<?php

namespace App\Services;

use Carbon\CarbonImmutable;

class SteamService
{
    public function __construct(private readonly ExchangeRateService $exchangeRates) {}

    public function search(string $query, int $limit = 8): array
    {
        $candidates = $this->storeSearch($query, 'ru', 'russian', true, $limit);
        if ($candidates !== []) {
            return $candidates;
        }

        return $this->storeSearch($query, 'us', 'english', false, $limit);
    }

    private function storeSearch(string $query, string $cc, string $lang, bool $availableInRu, int $limit): array
    {
        try {
            $resp = HttpClientFactory::make()->get('https://store.steampowered.com/api/storesearch/', [
                'term' => $query,
                'l' => $lang,
                'cc' => strtoupper($cc),
            ]);
            if (! $resp->successful()) {
                return [];
            }
            $payload = $resp->json() ?? [];
        } catch (\Throwable) {
            return [];
        }

        $out = [];
        foreach ($payload['items'] ?? [] as $item) {
            $type = $item['type'] ?? null;
            if ($type !== null && ! in_array($type, ['app', 'game'], true)) {
                continue;
            }
            $appid = $item['id'] ?? null;
            $name = $item['name'] ?? null;
            if (! $appid || ! $name) {
                continue;
            }
            $price = $item['price'] ?? null;
            $final = $availableInRu && is_array($price) ? ($price['final'] ?? null) : null;
            $initial = $availableInRu && is_array($price) ? ($price['initial'] ?? null) : null;
            $finalRub = $final !== null ? round($final / 100, 2) : null;
            $initialRub = $initial !== null ? round($initial / 100, 2) : null;
            $discount = 0;
            if ($initialRub && $finalRub !== null && $initialRub > 0 && $finalRub < $initialRub) {
                $discount = (int) round((1 - $finalRub / $initialRub) * 100);
            }
            $out[] = [
                'appid' => (int) $appid,
                'name' => (string) $name,
                'tiny_image' => $item['tiny_image'] ?? null,
                'header_image' => $item['tiny_image'] ?? null,
                'price_rub' => $finalRub,
                'price_initial_rub' => $initialRub,
                'discount_percent' => $discount,
                'is_free' => $availableInRu && $finalRub === 0.0,
                'available_in_ru' => $availableInRu,
            ];
            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }

    public function details(int $appid, ?string $fallbackName = null): array
    {
        $storeUrl = "https://store.steampowered.com/app/{$appid}/";
        $data = $this->fetchDetails($appid, 'ru', 'russian');
        $availableInRu = $data !== null;
        $note = null;

        if ($data === null) {
            $data = $this->fetchDetails($appid, 'us', 'english');
            if ($data === null) {
                return [
                    'appid' => $appid,
                    'name' => $fallbackName ?: "App {$appid}",
                    'header_image' => null,
                    'store_url' => $storeUrl,
                    'price_rub' => null,
                    'price_initial_rub' => null,
                    'discount_percent' => 0,
                    'is_free' => false,
                    'available_in_ru' => false,
                    'currency' => 'RUB',
                    'note' => 'Игра не найдена в Steam или недоступна в регионе RU.',
                ];
            }
            $note = 'В Steam RU игра сейчас недоступна. Цена RU отсутствует.';
        }

        $price = $data['price_overview'] ?? null;
        $isFree = (bool) ($data['is_free'] ?? false);
        $final = null;
        $initial = null;
        $discount = 0;
        if ($availableInRu) {
            if (is_array($price)) {
                $final = isset($price['final']) ? round($price['final'] / 100, 2) : ($isFree ? 0.0 : null);
                $initial = isset($price['initial']) ? round($price['initial'] / 100, 2) : ($isFree ? 0.0 : null);
                $discount = (int) ($price['discount_percent'] ?? 0);
            } elseif ($isFree) {
                $final = 0.0;
                $initial = 0.0;
            }
        }

        return [
            'appid' => $appid,
            'name' => $data['name'] ?? $fallbackName ?? "App {$appid}",
            'header_image' => $data['header_image'] ?? $data['capsule_image'] ?? null,
            'store_url' => $storeUrl,
            'price_rub' => $final,
            'price_initial_rub' => $initial,
            'discount_percent' => $discount,
            'is_free' => $isFree && $availableInRu,
            'available_in_ru' => $availableInRu,
            'currency' => 'RUB',
            'note' => $note,
        ];
    }

    /**
     * Refresh-only variant: a Steam transport/API failure is an exception,
     * while a valid unavailable app remains an empty normalized result.
     */
    public function refreshDetails(int $appid, ?string $fallbackName = null): array
    {
        $storeUrl = "https://store.steampowered.com/app/{$appid}/";
        $data = null;
        $availableInRu = false;
        $errors = [];
        $regionalPrices = [];
        foreach ((array) config('gpa.steam_price_regions', []) as $region) {
            $response = $this->fetchDetailsForRefresh($appid, (string) $region['country'], (string) $region['language']);
            if ($response['error']) {
                $errors[] = $response['error'];
            }
            $regionData = $response['data'];
            if ($regionData === null) {
                continue;
            }
            $data ??= $regionData;
            if (($region['region'] ?? null) === 'RU') {
                $availableInRu = true;
            }
            $overview = $regionData['price_overview'] ?? null;
            if (! is_array($overview) || ! isset($overview['final'])) {
                continue;
            }
            $amount = round($overview['final'] / 100, 2);
            $currency = (string) ($overview['currency'] ?? $region['currency']);
            $regionalPrices[] = [
                'region' => (string) $region['region'],
                'label' => (string) $region['label'],
                'currency' => $currency,
                'amount' => $amount,
                'price_rub' => $this->exchangeRates->rubFor($currency, $amount),
            ];
        }

        if ($data === null) {
            if ($errors !== []) {
                throw new \RuntimeException($errors[0]);
            }
            return [
                'appid' => $appid,
                'name' => $fallbackName ?: "App {$appid}",
                'header_image' => null,
                'store_url' => $storeUrl,
                'price_rub' => null,
                'release_status' => 'unknown',
                'release_date' => null,
                'regional_prices' => [],
            ];
        }

        $isFree = (bool) ($data['is_free'] ?? false);
        $ru = collect($regionalPrices)->firstWhere('region', 'RU');
        if ($availableInRu && $isFree && ! $ru) {
            $ru = ['amount' => 0.0, 'currency' => 'RUB', 'price_rub' => 0.0];
            $regionalPrices[] = ['region' => 'RU', 'label' => 'Россия', ...$ru];
        }
        $release = $data['release_date'] ?? [];
        $releaseDate = null;
        if (! empty($release['date'])) {
            try {
                $releaseDate = CarbonImmutable::parse((string) $release['date'])->toDateString();
            } catch (\Throwable) {
                // Steam localizes this value; coming_soon is the reliable signal.
            }
        }

        return [
            'appid' => $appid,
            'name' => $data['name'] ?? $fallbackName ?? "App {$appid}",
            'header_image' => $data['header_image'] ?? $data['capsule_image'] ?? null,
            'store_url' => $storeUrl,
            'price_rub' => $ru['price_rub'] ?? null,
            'release_status' => ! empty($release['coming_soon']) ? 'announced' : 'released',
            'release_date' => $releaseDate,
            'regional_prices' => $regionalPrices,
        ];
    }

    private function fetchDetails(int $appid, string $cc, string $lang): ?array
    {
        try {
            $resp = HttpClientFactory::make()->get('https://store.steampowered.com/api/appdetails', [
                'appids' => $appid,
                'cc' => $cc,
                'l' => $lang,
            ]);
            if (! $resp->successful()) {
                return null;
            }
            $block = $resp->json((string) $appid) ?? [];
            if (! ($block['success'] ?? false)) {
                return null;
            }

            return $block['data'] ?? null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function fetchDetailsForRefresh(int $appid, string $cc, string $lang): array
    {
        try {
            $response = HttpClientFactory::make()->get('https://store.steampowered.com/api/appdetails', [
                'appids' => $appid,
                'cc' => $cc,
                'l' => $lang,
            ]);
            if (! $response->successful()) {
                return ['data' => null, 'error' => 'Steam HTTP '.$response->status()];
            }
            $block = $response->json((string) $appid) ?? [];
            if (! ($block['success'] ?? false)) {
                return ['data' => null, 'error' => null];
            }

            return ['data' => $block['data'] ?? null, 'error' => null];
        } catch (\Throwable $e) {
            return ['data' => null, 'error' => 'Steam unavailable: '.mb_substr($e->getMessage(), 0, 180)];
        }
    }

    public function pickBest(array $candidates, string $query): ?array
    {
        if ($candidates === []) {
            return null;
        }
        $q = mb_strtolower(trim($query));
        foreach ($candidates as $c) {
            if (mb_strtolower($c['name']) === $q) {
                return $c;
            }
        }
        foreach ($candidates as $c) {
            if (str_starts_with(mb_strtolower($c['name']), $q)) {
                return $c;
            }
        }
        foreach ($candidates as $c) {
            if (str_contains(mb_strtolower($c['name']), $q)) {
                return $c;
            }
        }

        return $candidates[0];
    }
}
