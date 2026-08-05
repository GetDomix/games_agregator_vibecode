<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Скидки недели в Steam: эндпоинт featuredcategories (спецпредложения) +
 * детальная подгрузка цен через appdetails (RU-витрина).
 *
 * Ответ кэшируется на 30 минут — витрина Steam не меняется быстрее этого,
 * а лимит запросов к Steam у нас жёсткий (gpa.price_source_budgets.steam).
 *
 * Важно: цены в featuredcategories приходят в копейках (199900 = 1999 ₽),
 * поэтому для отображения делим на 100.
 */
class SteamWeeklyDealsService
{
    private const CACHE_KEY = 'steam-weekly-deals-v1';

    private const CACHE_TTL_SECONDS = 1800;

    private const FEATURED_URL = 'https://store.steampowered.com/api/featuredcategories';

    private const APP_DETAILS_URL = 'https://store.steampowered.com/api/appdetails';

    private const DETAILS_LIMIT = 24;

    private const DETAILS_TIMEOUT_SECONDS = 10;

    /** @return array{generated_at: string, currency: string, items: list<array<string, mixed>>} */
    public function deals(): array
    {
        return Cache::remember(self::CACHE_KEY, self::CACHE_TTL_SECONDS, fn () => $this->fetch());
    }

    public function refresh(): array
    {
        Cache::forget(self::CACHE_KEY);

        return $this->deals();
    }

    /** @return array{generated_at: string, currency: string, items: list<array<string, mixed>>} */
    private function fetch(): array
    {
        $candidates = $this->fetchCandidates();
        $details = $this->fetchDetails(array_slice($candidates, 0, self::DETAILS_LIMIT));

        $items = [];
        $fallbackImage = [];

        foreach ($candidates as $cand) {
            $appid = (int) $cand['id'];
            $det = $details[$appid] ?? null;

            if ($det !== null && $det['ok']) {
                // Детали из appdetails надёжнее каталожных полей (final_price
                // в каталоге не учитывает региональные корректировки витрины).
                $items[] = $this->item(
                    $appid,
                    $det['name'] ?: $cand['name'],
                    $det['image'],
                    $det['initial'],
                    $det['final'],
                    $det['discount']
                );
                continue;
            }

            // Только каталожные данные: цены в копейках → делим на 100.
            $final = (int) ($cand['final_price'] ?? 0);
            $initial = (int) ($cand['original_price'] ?? 0);
            if ($final > 0 && $initial > $final) {
                $items[] = $this->item(
                    $appid,
                    $cand['name'],
                    $cand['image'],
                    intdiv($initial, 100),
                    intdiv($final, 100),
                    (int) round(($initial - $final) / max(1, $initial) * 100)
                );
                $fallbackImage[$appid] = $cand['image'];
            } elseif ($final > 0) {
                // Скидка не подтверждена — в подборку не берём.
                unset($details[$appid]);
            }
        }

        usort($items, static fn (array $a, array $b): int => [$b['discount_percent'], $b['savings_rub']] <=> [$a['discount_percent'], $a['savings_rub']]);
        $items = array_slice($items, 0, 24);

        return [
            'generated_at' => now()->toIso8601String(),
            'currency' => 'RUB',
            'items' => $items,
        ];
    }

    /** @return list<array{id: int, name: string, image: string|null, original_price: int, final_price: int, discount_percent: int}> */
    private function fetchCandidates(): array
    {
        $response = Http::acceptJson()
            ->timeout((int) config('gpa.http_timeout', 20))
            ->retry(2, 350, throw: false)
            ->get(self::FEATURED_URL, ['cc' => 'ru', 'l' => 'russian', 'max_discounted' => 40]);

        if ($response->failed()) {
            return [];
        }

        $data = $response->json();
        if (! is_array($data)) {
            return [];
        }

        $raw = [];
        foreach ($data['specials']['items'] ?? [] as $row) {
            if (is_array($row)) {
                $raw[] = $row;
            }
        }
        foreach ($data as $key => $section) {
            if (is_string($key) && str_starts_with($key, 'cat_dailydeal') && is_array($section)) {
                foreach ($section['items'] ?? [] as $row) {
                    if (is_array($row)) {
                        $raw[] = $row;
                    }
                }
            }
        }

        $seen = [];
        $out = [];
        foreach ($raw as $row) {
            $appid = (int) ($row['id'] ?? 0);
            $discount = (int) ($row['discount_percent'] ?? 0);
            $final = (int) ($row['final_price'] ?? 0);
            if ($appid < 1 || $discount < 1 || $final < 1 || isset($seen[$appid])) {
                continue;
            }
            $seen[$appid] = true;
            $out[] = [
                'id' => $appid,
                'name' => trim((string) ($row['name'] ?? '')),
                'image' => is_string($row['large_capsule_image'] ?? null) ? $row['large_capsule_image']
                    : (is_string($row['small_capsule_image'] ?? null) ? $row['small_capsule_image'] : null),
                'original_price' => (int) ($row['original_price'] ?? 0),
                'final_price' => $final,
                'discount_percent' => $discount,
            ];
        }

        usort($out, static fn (array $a, array $b): int => $b['discount_percent'] <=> $a['discount_percent']);

        return $out;
    }

    /**
     * Детальные цены RU-витрины пачкой.
     *
     * @param list<array{id: int, name: string, image: string|null}> $candidates
     * @return array<int, array{ok: bool, name?: string, image?: string|null, initial?: int, final?: int, discount?: int}>
     */
    private function fetchDetails(array $candidates): array
    {
        if ($candidates === []) {
            return [];
        }

        $appids = array_map(static fn (array $c): int => $c['id'], $candidates);

        try {
            $response = Http::acceptJson()
                ->timeout(self::DETAILS_TIMEOUT_SECONDS)
                ->retry(1, 200, throw: false)
                ->get(self::APP_DETAILS_URL, [
                    'appids' => implode(',', $appids),
                    'cc' => 'ru',
                    'filters' => 'basic',
                ]);
        } catch (\Throwable) {
            return [];
        }

        if ($response->failed()) {
            return [];
        }

        $data = $response->json();
        if (! is_array($data)) {
            return [];
        }

        $out = [];
        foreach ($appids as $appid) {
            $entry = $data[(string) $appid] ?? null;
            if (! is_array($entry) || ! ($entry['success'] ?? false)) {
                $out[$appid] = ['ok' => false];
                continue;
            }
            $info = is_array($entry['data'] ?? null) ? $entry['data'] : [];
            $overview = is_array($info['price_overview'] ?? null) ? $info['price_overview'] : null;
            if ($overview === null) {
                // Бесплатная игра или нет данных по региону — скидку посчитать нельзя.
                $out[$appid] = ['ok' => false];
                continue;
            }
            $initial = (int) ($overview['initial'] ?? 0);
            $final = (int) ($overview['final'] ?? 0);
            if ($final < 1 || $initial <= $final) {
                $out[$appid] = ['ok' => false];
                continue;
            }
            $out[$appid] = [
                'ok' => true,
                'name' => trim((string) ($info['name'] ?? '')),
                'image' => is_string($info['header_image'] ?? null) ? $info['header_image'] : null,
                // price_overview приходит в копейках — переводим в рубли.
                'initial' => intdiv($initial, 100),
                'final' => intdiv($final, 100),
                'discount' => (int) ($overview['discount_percent'] ?? round(($initial - $final) / max(1, $initial) * 100)),
            ];
        }

        return $out;
    }

    /** @return array<string, mixed> */
    private function item(int $appid, string $name, ?string $image, int $initialRub, int $finalRub, int $discountPercent): array
    {
        return [
            'appid' => $appid,
            'name' => mb_substr($name, 0, 200),
            'header_image' => $image,
            'price_initial_rub' => $initialRub,
            'price_final_rub' => $finalRub,
            'discount_percent' => max(1, min(95, $discountPercent)),
            'savings_rub' => max(0, $initialRub - $finalRub),
        ];
    }
}
