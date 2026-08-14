<?php

namespace App\Services\Pricing;

use App\Services\Catalog\CatalogNameMatch;
use App\Services\Catalog\Classifier;
use App\Services\Catalog\MarketQuery;
use App\Services\Catalog\OfferRelevance;

/**
 * Plati.Market via Digiseller public endpoints.
 *
 * Real site flow (not free-text lot titles):
 *  1. /api/suggest.ashx → catalog game cards (type «Игры»)
 *  2. /asp/block_goods_category_2.asp?id_cb=… → all lots for that game card
 *
 * Sellers name lots arbitrarily; the game card is the source of truth.
 */
class PlatiService
{
    /**
     * Live / string search (no DB cache). Used by Aggregator when Game row is optional.
     *
     * @return array{0: list<array<string, mixed>>, 1: int, 2: ?string}
     */
    public function search(string $query): array
    {
        $query = trim($query);
        if ($query === '') {
            return [[], 0, null];
        }

        $resolved = $this->resolveGame($query);
        if ($resolved === null) {
            return $this->searchRelevantLots($query);
        }

        [$offers, $total, $error] = $this->productsForGame((int) $resolved['id_cb'], (string) $resolved['name']);
        if ($offers !== []) {
            return [$offers, $total, $error];
        }

        return $this->searchRelevantLots($query, $error);
    }

    /**
     * Refresh path for a persisted Game: use cached id_cb when fresh, re-suggest when needed.
     *
     * @return array{0: list<array<string, mixed>>, 1: int, 2: ?string}
     */
    public function searchForGame(\App\Models\Game $game, bool $forceResolve = false): array
    {
        $idCb = $this->idCbForGame($game, $forceResolve);
        if ($idCb === null) {
            return $this->searchRelevantLots((string) $game->name);
        }

        [$offers, $total, $error] = $this->productsForGame($idCb, (string) $game->name);
        if ($offers !== []) {
            return [$offers, $total, $error];
        }

        // Cached card returned nothing — id may be stale; re-suggest once.
        if ($error === null && ! $forceResolve && $game->plati_id_cb) {
            $freshId = $this->idCbForGame($game, true);
            if ($freshId !== null) {
                [$freshOffers, $freshTotal, $freshError] = $this->productsForGame($freshId, (string) $game->name);
                if ($freshOffers !== []) {
                    return [$freshOffers, $freshTotal, $freshError];
                }
                $error = $freshError;
            }
        }

        // Plati does not create a /games/... catalog card for every title.
        // Its full-title product API is therefore a required fallback, not a
        // fuzzy primary source. OfferRelevance keeps only lots belonging to
        // this exact Steam-resolved game.
        return $this->searchRelevantLots((string) $game->name, $error);
    }

    /**
     * @return array{0: list<array<string, mixed>>, 1: int, 2: ?string}
     */
    private function searchRelevantLots(string $gameName, ?string $catalogError = null): array
    {
        [$offers, , $searchError] = MarketQuery::searchMerged(
            fn (string $query): array => $this->searchLots($query),
            $gameName
        );
        $offers = OfferRelevance::filter($offers, $gameName);
        $offers = array_map(static function (array $offer): array {
            unset($offer['_relevance']);

            return $offer;
        }, $offers);

        if ($offers === [] && $searchError !== null) {
            return [[], 0, $catalogError ?? $searchError];
        }

        return [$offers, count($offers), null];
    }

    /**
     * Search Plati lots by the complete Steam title. The caller applies the
     * exact-game relevance filter before anything is persisted.
     *
     * @return array{0: list<array<string, mixed>>, 1: int, 2: ?string}
     */
    private function searchLots(string $query): array
    {
        $pageSize = max(1, min((int) config('gpa.plati_page_size', 100), 100));
        $maxPages = max(1, (int) config('gpa.plati_max_pages', 5));
        $partnerId = (string) config('gpa.digiseller_partner_id', '');
        $offers = [];
        $totalPages = 1;
        $lastError = null;

        for ($page = 1; $page <= $maxPages; $page++) {
            $payload = null;
            foreach (['https://plati.market/api/search.ashx', 'https://plati.io/api/search.ashx'] as $endpoint) {
                try {
                    $response = HttpClientFactory::make()->get($endpoint, [
                        'query' => $query,
                        'pagesize' => $pageSize,
                        'pagenum' => $page,
                        'response' => 'json',
                    ]);
                    if ($response->successful() && is_array($response->json())) {
                        $payload = $response->json();
                        break;
                    }
                    $lastError = 'Plati HTTP '.$response->status();
                } catch (\Throwable $error) {
                    $lastError = $error->getMessage();
                }
            }

            if ($payload === null) {
                if ($page === 1) {
                    return [[], 0, $lastError ?: 'Plati API unavailable'];
                }
                break;
            }

            $totalPages = max(1, (int) ($payload['Totalpages'] ?? $payload['totalpages'] ?? 1));
            $items = is_array($payload['items'] ?? null) ? $payload['items'] : [];
            if ($items === []) {
                break;
            }

            foreach ($items as $item) {
                if (! is_array($item)) {
                    continue;
                }
                $price = $item['price_rur'] ?? $item['price_rub'] ?? null;
                if (! is_numeric($price) || (float) $price <= 0) {
                    continue;
                }
                $title = trim((string) ($item['name'] ?? $item['name_eng'] ?? ''));
                if ($title === '') {
                    continue;
                }
                $url = (string) ($item['url'] ?? ('https://plati.market/itm/'.($item['id'] ?? '')));
                if ($partnerId !== '') {
                    $url .= (str_contains($url, '?') ? '&' : '?').'ai='.$partnerId;
                }
                $priceRub = round((float) $price, 2);
                $offers[] = [
                    'title' => $title,
                    'url' => $url,
                    'price_rub' => $priceRub,
                    'prices' => array_filter([
                        'RUB' => $priceRub,
                        'USD' => $this->positivePrice($item['price_usd'] ?? null),
                        'EUR' => $this->positivePrice($item['price_eur'] ?? null),
                    ], static fn ($value): bool => $value !== null),
                    'sales' => (int) ($item['numsold'] ?? 0),
                    'seller_name' => $item['seller_name'] ?? null,
                    'kind' => Classifier::fromText($title, (string) ($item['description'] ?? '')),
                    'external_id' => isset($item['id']) ? (string) $item['id'] : null,
                ];
            }

            if ($page >= $totalPages) {
                break;
            }
        }

        return [$offers, $offers === [] ? 0 : $totalPages * $pageSize, null];
    }

    private function positivePrice(mixed $value): ?float
    {
        return is_numeric($value) && (float) $value > 0 ? round((float) $value, 2) : null;
    }

    /**
     * Resolve Plati game card id for a Game, with TTL / negative-cache.
     */
    public function idCbForGame(\App\Models\Game $game, bool $force = false): ?int
    {
        $cacheIsFresh = $game->plati_id_cb
            ? ! $this->catalogCacheStale($game->plati_catalog_resolved_at)
            : ! $this->negativeCatalogCacheStale($game->plati_catalog_resolved_at);
        if (! $force && $cacheIsFresh) {
            // Positive cache
            if ($game->plati_id_cb) {
                return (int) $game->plati_id_cb;
            }

            // Negative cache: recently confirmed "no card"
            return null;
        }

        $resolved = $this->resolveGame((string) $game->name);
        $game->forceFill([
            'plati_id_cb' => $resolved['id_cb'] ?? null,
            'plati_catalog_name' => $resolved['name'] ?? null,
            'plati_catalog_resolved_at' => now(),
        ])->save();

        return $resolved['id_cb'] ?? null;
    }

    /**
     * @return array{id_cb: int, name: string, link: string}|null
     */
    public function resolveGame(string $steamName): ?array
    {
        $suggestions = $this->suggest($steamName);
        $entities = [];
        foreach ($suggestions as $row) {
            if (($row['kind'] ?? '') === 'game' && ($row['id_cb'] ?? 0) > 0) {
                $entities[] = $row;
            }
        }

        $picked = CatalogNameMatch::pick($entities, $steamName);
        if ($picked === null) {
            return null;
        }

        return [
            'id_cb' => (int) $picked['id_cb'],
            'name' => (string) $picked['name'],
            'link' => (string) ($picked['link'] ?? ''),
        ];
    }

    private function catalogCacheStale(?\Carbon\CarbonInterface $resolvedAt): bool
    {
        if ($resolvedAt === null) {
            return true;
        }
        $ttlDays = max(1, (int) config('gpa.catalog_id_ttl_days', 14));

        return $resolvedAt->lt(now()->subDays($ttlDays));
    }

    private function negativeCatalogCacheStale(?\Carbon\CarbonInterface $resolvedAt): bool
    {
        if ($resolvedAt === null) {
            return true;
        }
        $ttlHours = max(1, (int) config('gpa.catalog_negative_ttl_hours', 1));

        return $resolvedAt->lt(now()->subHours($ttlHours));
    }

    /**
     * @return list<array{name: string, kind: string, id_cb: ?int, link: string}>
     */
    public function suggest(string $term): array
    {
        try {
            $resp = HttpClientFactory::make()
                ->withHeaders([
                    'Accept' => 'application/json',
                    'Referer' => 'https://plati.market/',
                    'X-Requested-With' => 'XMLHttpRequest',
                ])
                ->get('https://plati.market/api/suggest.ashx', [
                    'q' => mb_substr($term, 0, 100),
                    'lang' => 'ru-RU',
                    'geo' => 'ru',
                    'v' => 2,
                ]);
            if (! $resp->successful()) {
                return [];
            }
            $payload = $resp->json();
        } catch (\Throwable) {
            return [];
        }

        if (! is_array($payload)) {
            return [];
        }

        $out = [];
        foreach ($payload as $row) {
            if (! is_array($row)) {
                continue;
            }
            $name = trim((string) ($row['name'] ?? ''));
            $link = (string) ($row['link'] ?? '');
            $type = (string) ($row['type'] ?? '');
            if ($name === '') {
                continue;
            }
            $idCb = null;
            $kind = 'product';
            // /games/hades/948/ → catalog game card
            if (preg_match('#^/games/[^/]+/(\d+)/?#', $link, $m)) {
                $idCb = (int) $m[1];
                $kind = 'game';
            } elseif (preg_match('#^/cat/[^/]+/(\d+)/?#', $link, $m)) {
                $idCb = (int) $m[1];
                $kind = 'category';
            } elseif (in_array($type, ['Игры', 'Games', 'game'], true)) {
                $kind = 'game';
            }
            $out[] = [
                'name' => $name,
                'kind' => $kind,
                'id_cb' => $idCb,
                'link' => $link,
                'type' => $type,
            ];
        }

        return $out;
    }

    /**
     * @return array{0: list<array<string, mixed>>, 1: int, 2: ?string}
     */
    public function productsForGame(int $idCb, string $gameName = ''): array
    {
        $pageSize = max(1, min((int) config('gpa.plati_page_size', 100), 100));
        // Site uses 24 rows/page; we request larger pages when the endpoint allows.
        $rows = min(48, $pageSize);
        $maxPages = max(1, (int) config('gpa.plati_max_pages', 5));
        $partnerId = (string) config('gpa.digiseller_partner_id', '');

        $offers = [];
        $total = 0;
        $error = null;

        for ($page = 1; $page <= $maxPages; $page++) {
            try {
                $resp = HttpClientFactory::make()
                    ->withHeaders([
                        'Accept' => '*/*',
                        'Referer' => "https://plati.market/games/x/{$idCb}/",
                        'X-Requested-With' => 'XMLHttpRequest',
                    ])
                    ->get('https://plati.market/asp/block_goods_category_2.asp', [
                        'id_cb' => $idCb,
                        'id_c' => 0,
                        'sort' => '',
                        'page' => $page,
                        'rows' => $rows,
                        'curr' => 'rub',
                        'lang' => 'ru',
                    ]);
                if (! $resp->successful()) {
                    $error = 'Plati HTTP '.$resp->status();
                    if ($page === 1) {
                        return [[], 0, $error];
                    }
                    break;
                }
                $raw = (string) $resp->body();
            } catch (\Throwable $e) {
                $error = $e->getMessage();
                if ($page === 1) {
                    return [[], 0, $error];
                }
                break;
            }

            $html = $raw;
            if ($page === 1 && str_contains($raw, '|')) {
                $parts = explode('|', $raw, 4);
                if (count($parts) === 4 && is_numeric($parts[0])) {
                    $total = (int) $parts[0];
                    $html = $parts[3];
                }
            }

            $pageOffers = $this->parseProductCards($html, $partnerId);
            if ($pageOffers === []) {
                break;
            }
            foreach ($pageOffers as $offer) {
                $offers[] = $offer;
            }

            if ($total > 0 && count($offers) >= $total) {
                break;
            }
            if (count($pageOffers) < $rows) {
                break;
            }
        }

        return [$offers, $total ?: count($offers), null];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function parseProductCards(string $html, string $partnerId): array
    {
        if ($html === '') {
            return [];
        }

        $offers = [];
        if (! preg_match_all(
            '#<a\b[^>]*\bhref="(/itm/[^"]+)"[^>]*\btitle="([^"]*)"[^>]*\bproduct_id="(\d+)"[^>]*>(.*?)</a>#si',
            $html,
            $matches,
            PREG_SET_ORDER
        )) {
            // Fallback: product_id then nearby title/price
            if (! preg_match_all('#product_id="(\d+)"#', $html, $ids)) {
                return [];
            }
        }

        if ($matches ?? null) {
            foreach ($matches as $m) {
                $path = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $title = html_entity_decode($m[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $id = $m[3];
                $chunk = $m[4];
                $price = $this->parsePriceRub($chunk);
                if ($price === null || $price <= 0 || $title === '') {
                    continue;
                }
                $url = 'https://plati.market'.$path;
                if ($partnerId !== '') {
                    $url .= (str_contains($url, '?') ? '&' : '?').'ai='.$partnerId;
                }
                $seller = null;
                if (preg_match('#text-truncate\'>([^<]+)</span>#u', $chunk, $sm)
                    || preg_match('#text-truncate">([^<]+)</span>#u', $chunk, $sm)) {
                    $seller = trim(html_entity_decode($sm[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                }
                $offers[] = [
                    'title' => $title,
                    'url' => $url,
                    'price_rub' => $price,
                    'prices' => ['RUB' => $price],
                    'sales' => 0,
                    'seller_name' => $seller,
                    'kind' => Classifier::fromText($title),
                    'external_id' => $id,
                ];
            }
        }

        return $offers;
    }

    private function parsePriceRub(string $html): ?float
    {
        // 1&nbsp;055&nbsp;₽ or 1 055 ₽
        if (preg_match('#class="title-bold[^"]*"[^>]*>([^<]+)</span>#u', $html, $m)) {
            $raw = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $raw = str_replace(["\xc2\xa0", ' ', '₽', 'руб', 'RUB'], '', mb_strtolower($raw));
            $raw = str_replace(',', '.', $raw);
            if (is_numeric($raw)) {
                return round((float) $raw, 2);
            }
        }
        if (preg_match('#(\d[\d\s\xc2\xa0]*)\s*₽#u', $html, $m)) {
            $raw = str_replace(["\xc2\xa0", ' '], '', $m[1]);
            if (is_numeric($raw)) {
                return round((float) $raw, 2);
            }
        }

        return null;
    }
}
