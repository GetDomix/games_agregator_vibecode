<?php

namespace App\Services;

use App\Models\CurrentGamePrice;
use App\Models\Game;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class SteamNewReleasesService
{
    private const URL = 'https://store.steampowered.com/api/featuredcategories';
    private const CACHE_KEY = 'steam-new-releases-v3';
    private const LAST_SUCCESS_KEY = 'steam-new-releases-last-success';
    private const REFRESH_MINUTES = 30;

    /** @return array{generated_at: string, currency: string, source: string, items: list<array<string, mixed>>} */
    public function releases(): array
    {
        $result = Cache::remember(self::CACHE_KEY, now()->addMinutes(self::REFRESH_MINUTES), function (): array {
            try {
                $response = HttpClientFactory::make()->get(self::URL, ['cc' => 'ru', 'l' => 'russian']);
                if (! $response->successful()) {
                    throw new \RuntimeException('Steam new releases returned HTTP '.$response->status());
                }

                $items = [];
                foreach (($response->json('new_releases.items') ?? []) as $row) {
                    if (! is_array($row) || (int) ($row['type'] ?? 0) !== 0 || empty($row['id']) || empty($row['name'])) {
                        continue;
                    }
                    $name = trim((string) $row['name']);
                    if (preg_match('/\b(demo|soundtrack|test server)\b/i', $name)) {
                        continue;
                    }
                    $items[] = [
                        'appid' => (int) $row['id'],
                        'name' => $name,
                        'header_image' => $row['header_image'] ?? $row['large_capsule_image'] ?? null,
                        'price_final_rub' => isset($row['final_price']) ? round((float) $row['final_price'] / 100, 2) : null,
                        'discount_percent' => (int) ($row['discount_percent'] ?? 0),
                    ];
                    if (count($items) >= 30) {
                        break;
                    }
                }

                if ($items !== []) {
                    $payload = $this->payload('steam', $items);
                    Cache::put(self::LAST_SUCCESS_KEY, $payload, now()->addDays(7));

                    return $payload;
                }
            } catch (\Throwable $exception) {
                Log::warning('steam_new_releases_unavailable', ['message' => $exception->getMessage()]);
            }

            try {
                $searchItems = $this->steamSearchFallback();
                if ($searchItems !== []) {
                    $payload = $this->payload('steam_search', $searchItems);
                    Cache::put(self::LAST_SUCCESS_KEY, $payload, now()->addDays(7));

                    return $payload;
                }
            } catch (\Throwable $exception) {
                Log::warning('steam_search_releases_unavailable', ['message' => $exception->getMessage()]);
            }

            $lastSuccess = Cache::get(self::LAST_SUCCESS_KEY);
            if (is_array($lastSuccess) && ($lastSuccess['items'] ?? []) !== []) {
                return [...$lastSuccess, 'source' => 'steam_stale'];
            }

            return $this->localFallback();
        });

        // Пустой ответ при кратком сетевом сбое не должен скрывать витрину
        // следующие 30 минут: следующий посетитель сразу попробует снова.
        if (($result['items'] ?? []) === []) {
            Cache::forget(self::CACHE_KEY);
        }

        return $result;
    }

    /** @param list<array<string, mixed>> $items */
    private function payload(string $source, array $items): array
    {
        return [
            'generated_at' => now()->toIso8601String(),
            'currency' => 'RUB',
            'source' => $source,
            'refresh_interval_minutes' => self::REFRESH_MINUTES,
            'items' => $items,
        ];
    }

    /** @return list<array<string, mixed>> */
    private function steamSearchFallback(): array
    {
        $response = HttpClientFactory::make()->get('https://store.steampowered.com/search/', [
            'filter' => 'popularnew',
            'sort_by' => 'Released_DESC',
            'cc' => 'ru',
            'l' => 'russian',
        ]);
        if (! $response->successful()) {
            throw new \RuntimeException('Steam search releases returned HTTP '.$response->status());
        }

        preg_match_all('/<a\b[^>]*data-ds-appid="(\d+)"[^>]*>(.*?)<\/a>/si', $response->body(), $matches, PREG_SET_ORDER);
        $items = [];
        $seen = [];
        foreach ($matches as $match) {
            $appid = (int) $match[1];
            if ($appid < 1 || isset($seen[$appid])) {
                continue;
            }
            preg_match('/<span\b[^>]*class="[^"]*title[^"]*"[^>]*>(.*?)<\/span>/si', $match[2], $titleMatch);
            $name = trim(html_entity_decode(strip_tags($titleMatch[1] ?? ''), ENT_QUOTES | ENT_HTML5));
            if ($name === '') {
                continue;
            }
            preg_match('/<img\b[^>]*src="([^"]+)"/si', $match[2], $imageMatch);
            $seen[$appid] = true;
            $items[] = [
                'appid' => $appid,
                'name' => mb_substr($name, 0, 200),
                'header_image' => isset($imageMatch[1]) ? html_entity_decode($imageMatch[1], ENT_QUOTES | ENT_HTML5) : null,
                'price_final_rub' => null,
                'discount_percent' => 0,
            ];
            if (count($items) >= 30) {
                break;
            }
        }

        return $items;
    }

    /** @return array{generated_at: string, currency: string, source: string, items: list<array<string, mixed>>} */
    private function localFallback(): array
    {
        $games = Game::query()
            ->with(['currentPrices' => fn ($query) => $query
                ->where('source', 'steam')
                ->where('offer_kind', 'official')])
            ->where('release_status', Game::RELEASE_STATUS_RELEASED)
            ->whereNotNull('release_date')
            ->orderByDesc('release_date')
            ->limit(30)
            ->get();

        return $this->payload('local_fallback', $games->map(function (Game $game): array {
                /** @var CurrentGamePrice|null $price */
                $price = $game->currentPrices->first();

                return [
                    'appid' => (int) $game->steam_appid,
                    'name' => $game->name,
                    'header_image' => $game->header_image,
                    'price_final_rub' => $price?->min_price_rub !== null ? (float) $price->min_price_rub : null,
                    'discount_percent' => $price?->discount_percent !== null ? (int) $price->discount_percent : 0,
                ];
            })->values()->all());
    }
}
