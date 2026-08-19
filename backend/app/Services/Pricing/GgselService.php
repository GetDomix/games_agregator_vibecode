<?php

namespace App\Services\Pricing;

use App\Models\Game;
use App\Services\Catalog\CatalogNameMatch;
use App\Services\Catalog\Classifier;
use Carbon\CarbonInterface;

/**
 * GGsel catalog flow.
 *
 * The marketplace's own game card is authoritative:
 *  1. /elastic/goods/query-categories finds the platform card;
 *  2. /categories/{slug} returns its digi_catalog id;
 *  3. /elastic/goods/categories lists products by that id.
 *
 * Seller titles are deliberately not used to decide game identity.
 */
class GgselService
{
    /**
     * Live/string search without a persisted Game cache.
     *
     * @return array{0: list<array<string, mixed>>, 1: int, 2: ?string}
     */
    public function search(string $query): array
    {
        $query = trim($query);
        if ($query === '') {
            return [[], 0, null];
        }

        [$catalog, $error] = $this->resolveCatalog($query);
        if ($error !== null) {
            return [[], 0, $error];
        }
        if ($catalog === null) {
            return [[], 0, null];
        }

        return $this->productsForCatalog((int) $catalog['digi_catalog_id']);
    }

    /**
     * Refresh a persisted game, normally using one cached numeric catalog id.
     *
     * @return array{0: list<array<string, mixed>>, 1: int, 2: ?string}
     */
    public function searchForGame(Game $game, bool $forceResolve = false): array
    {
        $usedFreshCache = ! $forceResolve
            && (int) $game->ggsel_digi_catalog_id > 0
            && ! $this->positiveCatalogCacheStale($game->ggsel_catalog_resolved_at);

        [$catalog, $resolveError] = $this->catalogForGame($game, $forceResolve);
        if ($resolveError !== null) {
            return [[], 0, $resolveError];
        }
        if ($catalog === null) {
            return [[], 0, null];
        }

        [$offers, $total, $productError] = $this->productsForCatalog((int) $catalog['digi_catalog_id']);
        if ($offers !== [] || $productError !== null || ! $usedFreshCache) {
            return [$offers, $total, $productError];
        }

        // A formerly valid pointer can be retired or remapped by GGsel.
        // Re-resolve once, then stop; never fall back to arbitrary lot text.
        [$freshCatalog, $freshResolveError] = $this->catalogForGame($game->fresh(), true);
        if ($freshResolveError !== null) {
            return [[], 0, $freshResolveError];
        }
        if ($freshCatalog === null) {
            return [[], 0, null];
        }

        return $this->productsForCatalog((int) $freshCatalog['digi_catalog_id']);
    }

    /** Compatibility helper retained for internal callers/tests. */
    public function categoryNameForGame(Game $game, bool $force = false): ?string
    {
        [$catalog] = $this->catalogForGame($game, $force);

        return $catalog['name'] ?? null;
    }

    /**
     * @return array{0: ?array{digi_catalog_id: int, category_id: int, name: string, url: string}, 1: ?string}
     */
    private function catalogForGame(Game $game, bool $force): array
    {
        $hasId = (int) $game->ggsel_digi_catalog_id > 0;
        $cacheIsFresh = $hasId
            ? ! $this->positiveCatalogCacheStale($game->ggsel_catalog_resolved_at)
            : ! $this->negativeCatalogCacheStale($game->ggsel_catalog_resolved_at);

        // Old rows may have only name/slug from the former text-search path.
        // Resolve them immediately so they acquire the authoritative numeric id.
        $legacyPointerNeedsUpgrade = ! $hasId
            && ($game->ggsel_category_slug || $game->ggsel_category_name);

        if (! $force && $cacheIsFresh && ! $legacyPointerNeedsUpgrade) {
            if (! $hasId) {
                return [null, null];
            }

            return [[
                'digi_catalog_id' => (int) $game->ggsel_digi_catalog_id,
                'category_id' => 0,
                'name' => (string) ($game->ggsel_category_name ?: $game->name),
                'url' => (string) ($game->ggsel_category_slug ?: ''),
            ], null];
        }

        [$resolved, $error] = $this->resolveCatalog((string) $game->name);
        if ($error !== null) {
            // A temporary discovery outage must not destroy a working pointer.
            if ($hasId) {
                return [[
                    'digi_catalog_id' => (int) $game->ggsel_digi_catalog_id,
                    'category_id' => 0,
                    'name' => (string) ($game->ggsel_category_name ?: $game->name),
                    'url' => (string) ($game->ggsel_category_slug ?: ''),
                ], null];
            }

            return [null, $error];
        }

        $game->forceFill([
            'ggsel_category_slug' => $resolved['url'] ?? null,
            'ggsel_digi_catalog_id' => $resolved['digi_catalog_id'] ?? null,
            'ggsel_category_name' => $resolved['name'] ?? null,
            'ggsel_catalog_resolved_at' => now(),
        ])->save();

        return [$resolved, null];
    }

    /**
     * @return array{0: ?array{digi_catalog_id: int, category_id: int, name: string, url: string}, 1: ?string}
     */
    private function resolveCatalog(string $steamName): array
    {
        [$categories, $error] = $this->fetchCategorySuggestions($steamName);
        if ($error !== null) {
            return [null, $error];
        }

        $picked = CatalogNameMatch::pick($categories, $steamName);
        $slug = trim((string) ($picked['url'] ?? ''));
        if ($picked === null || $slug === '') {
            return [null, null];
        }

        [$details, $detailError] = $this->fetchCategoryDetails($slug);
        if ($detailError !== null) {
            return [null, $detailError];
        }
        if ($details === null || (int) ($details['digi_catalog'] ?? 0) <= 0) {
            return [null, null];
        }

        // The resolved URL must still describe the selected Steam game.
        $detailName = trim((string) ($details['name'] ?? $picked['name'] ?? ''));
        if (CatalogNameMatch::pick([['name' => $detailName]], $steamName) === null) {
            return [null, null];
        }

        return [[
            'digi_catalog_id' => (int) $details['digi_catalog'],
            'category_id' => (int) ($details['id'] ?? 0),
            'name' => $detailName,
            'url' => trim((string) ($details['url'] ?? $slug)) ?: $slug,
        ], null];
    }

    /**
     * @return array{0: list<array{name: string, url: string}>, 1: ?string}
     */
    private function fetchCategorySuggestions(string $query): array
    {
        $limit = max(20, min((int) config('gpa.ggsel_limit', 100), 200));

        try {
            $response = HttpClientFactory::make()
                ->withHeaders($this->browserHeaders())
                ->post('https://api.ggsel.com/elastic/goods/query-categories', [
                    'search_term' => $query,
                    'lang' => 'ru',
                    'limit' => $limit,
                    'is_russian_ip' => true,
                ]);
            if (! $response->successful()) {
                return [[], 'GGsel HTTP '.$response->status()];
            }
            $rows = $response->json('data');
        } catch (\Throwable $error) {
            return [[], $error->getMessage()];
        }

        $categories = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            if (! is_array($row)) {
                continue;
            }
            $name = trim((string) ($row['name'] ?? ''));
            $url = trim((string) ($row['url'] ?? ''));
            if ($name !== '' && $url !== '') {
                $categories[] = ['name' => $name, 'url' => $url];
            }
        }

        return [$categories, null];
    }

    /**
     * @return array{0: ?array<string, mixed>, 1: ?string}
     */
    private function fetchCategoryDetails(string $slug): array
    {
        try {
            $response = HttpClientFactory::make()
                ->withHeaders($this->browserHeaders())
                ->get('https://api.ggsel.com/categories/'.rawurlencode($slug));
            if (! $response->successful()) {
                return [null, 'GGsel HTTP '.$response->status()];
            }

            $payload = $response->json();
            // A few live category descriptions contain raw control bytes.
            // Strip only JSON-illegal bytes and retry parsing the same API response.
            if (! is_array($payload)) {
                $sanitized = preg_replace('/[\x00-\x1F\x7F]/', '', $response->body()) ?? '';
                $payload = json_decode($sanitized, true);
            }
            $data = is_array($payload) ? ($payload['data'] ?? null) : null;

            return [is_array($data) ? $data : null, is_array($data) ? null : 'GGsel invalid category response'];
        } catch (\Throwable $error) {
            return [null, $error->getMessage()];
        }
    }

    /**
     * @return array{0: list<array<string, mixed>>, 1: int, 2: ?string}
     */
    private function productsForCatalog(int $digiCatalogId): array
    {
        $limit = max(1, min((int) config('gpa.ggsel_limit', 100), 200));

        try {
            $response = HttpClientFactory::make()
                ->withHeaders($this->browserHeaders())
                ->post('https://api.ggsel.com/elastic/goods/categories', [
                    'digi_catalog' => $digiCatalogId,
                    'lang' => 'ru',
                    'limit' => $limit,
                    'is_russian_ip' => true,
                ]);
            if (! $response->successful()) {
                return [[], 0, 'GGsel HTTP '.$response->status()];
            }
            $data = $response->json('data');
        } catch (\Throwable $error) {
            return [[], 0, $error->getMessage()];
        }

        if (! is_array($data)) {
            return [[], 0, 'GGsel invalid products response'];
        }

        $items = is_array($data['items'] ?? null) ? $data['items'] : [];
        $total = (int) ($data['total'] ?? count($items));

        return [$this->normalizeOffers($items), $total, null];
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     */
    private function normalizeOffers(array $items): array
    {
        $partnerId = (string) config('gpa.digiseller_partner_id', '');
        $offers = [];

        foreach ($items as $item) {
            if (! is_array($item) || ($item['is_active'] ?? true) === false) {
                continue;
            }
            $price = $item['price_wmr'] ?? $item['price_rub'] ?? null;
            if (! is_numeric($price) || (float) $price <= 0) {
                continue;
            }

            $name = trim((string) ($item['name'] ?? 'Товар'));
            $searchTitle = trim((string) ($item['search_title'] ?? ''));
            $contentTypeId = isset($item['content_type_id']) ? (int) $item['content_type_id'] : null;
            $slug = $item['url'] ?? null;
            $id = $item['id_goods'] ?? $item['id'] ?? null;
            $url = $slug
                ? 'https://ggsel.net/catalog/product/'.$slug
                : ($id ? 'https://ggsel.net/catalog/product/'.$id : 'https://ggsel.net/');
            if ($partnerId !== '') {
                $url .= (str_contains($url, '?') ? '&' : '?').'ai='.$partnerId;
            }

            $priceRub = round((float) $price, 2);
            $offers[] = [
                'title' => $searchTitle !== '' ? "{$name} — {$searchTitle}" : $name,
                'url' => $url,
                'price_rub' => $priceRub,
                'prices' => array_filter([
                    'RUB' => $priceRub,
                    'USD' => $this->positivePrice($item['price_wmz'] ?? null),
                    'EUR' => $this->positivePrice($item['price_wme'] ?? null),
                ], static fn ($value): bool => $value !== null),
                'sales' => isset($item['cnt_sell']) && is_numeric($item['cnt_sell'])
                    ? max(0, (int) $item['cnt_sell'])
                    : null,
                'seller_name' => $item['seller_name'] ?? null,
                'kind' => Classifier::ggsel($contentTypeId, $name, $searchTitle),
                'external_id' => $id !== null ? (string) $id : null,
            ];
        }

        return $offers;
    }

    /** @return array<string, string> */
    private function browserHeaders(): array
    {
        return [
            'Content-Type' => 'application/json',
            'Origin' => 'https://ggsel.net',
            'Referer' => 'https://ggsel.net/',
        ];
    }

    private function positiveCatalogCacheStale(?CarbonInterface $resolvedAt): bool
    {
        if ($resolvedAt === null) {
            return true;
        }

        return $resolvedAt->lt(now()->subDays(max(1, (int) config('gpa.catalog_id_ttl_days', 14))));
    }

    private function negativeCatalogCacheStale(?CarbonInterface $resolvedAt): bool
    {
        if ($resolvedAt === null) {
            return true;
        }

        return $resolvedAt->lt(now()->subHours(max(1, (int) config('gpa.catalog_negative_ttl_hours', 1))));
    }

    private function positivePrice(mixed $value): ?float
    {
        return is_numeric($value) && (float) $value > 0 ? round((float) $value, 2) : null;
    }
}
