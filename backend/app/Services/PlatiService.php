<?php

namespace App\Services;

class PlatiService
{
    public function search(string $query): array
    {
        $pageSize = max(1, min((int) config('gpa.plati_page_size', 100), 100));
        $maxPages = max(1, (int) config('gpa.plati_max_pages', 5));
        $partnerId = (string) config('gpa.digiseller_partner_id', '');
        $offers = [];
        $totalPages = 1;
        $error = null;

        for ($page = 1; $page <= $maxPages; $page++) {
            $payload = null;
            foreach (['https://plati.market/api/search.ashx', 'https://plati.io/api/search.ashx'] as $base) {
                try {
                    $resp = HttpClientFactory::make()->get($base, [
                        'query' => $query,
                        'pagesize' => $pageSize,
                        'pagenum' => $page,
                        'response' => 'json',
                    ]);
                    if ($resp->successful()) {
                        $payload = $resp->json();
                        break;
                    }
                    $error = 'Plati HTTP '.$resp->status();
                } catch (\Throwable $e) {
                    $error = $e->getMessage();
                }
            }

            if ($payload === null) {
                if ($page === 1) {
                    return [[], 0, $error ?: 'Plati API unavailable'];
                }
                break;
            }

            $totalPages = max(1, (int) ($payload['Totalpages'] ?? $payload['totalpages'] ?? 1));
            $items = $payload['items'] ?? [];
            if ($items === []) {
                break;
            }

            foreach ($items as $item) {
                $price = $item['price_rur'] ?? $item['price_rub'] ?? null;
                $price = is_numeric($price) ? (float) $price : null;
                if ($price === null || $price <= 0) {
                    continue;
                }
                $name = (string) ($item['name'] ?? $item['name_eng'] ?? 'Товар');
                $url = (string) ($item['url'] ?? ('https://plati.market/itm/'.($item['id'] ?? '')));
                if ($partnerId !== '') {
                    $url .= (str_contains($url, '?') ? '&' : '?').'ai='.$partnerId;
                }
                $offers[] = [
                    'title' => $name,
                    'url' => $url,
                    'price_rub' => round($price, 2),
                    'sales' => (int) ($item['numsold'] ?? 0),
                    'seller_name' => $item['seller_name'] ?? null,
                    'kind' => Classifier::fromText($name, (string) ($item['description'] ?? '')),
                ];
            }

            if ($page >= $totalPages) {
                break;
            }
        }

        return [$offers, $offers ? $totalPages * $pageSize : 0, null];
    }
}
