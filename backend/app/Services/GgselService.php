<?php

namespace App\Services;

class GgselService
{
    public function search(string $query): array
    {
        $limit = max(1, min((int) config('gpa.ggsel_limit', 100), 200));
        $partnerId = (string) config('gpa.digiseller_partner_id', '');

        try {
            $resp = HttpClientFactory::make()
                ->withHeaders([
                    'Content-Type' => 'application/json',
                    'Origin' => 'https://ggsel.net',
                    'Referer' => 'https://ggsel.net/',
                ])
                ->post('https://api.ggsel.com/elastic/goods/query', [
                    'search_term' => $query,
                    'lang' => 'ru',
                    'limit' => $limit,
                    'is_russian_ip' => true,
                ]);
            if (! $resp->successful()) {
                return [[], 0, 'GGsel HTTP '.$resp->status()];
            }
            $data = $resp->json()['data'] ?? [];
        } catch (\Throwable $e) {
            return [[], 0, $e->getMessage()];
        }

        $items = is_array($data) && array_is_list($data) ? $data : ($data['items'] ?? []);
        $total = is_array($data) && ! array_is_list($data)
            ? (int) ($data['total'] ?? $data['total_count'] ?? count($items))
            : count($items);

        $offers = [];
        foreach ($items as $item) {
            if (($item['is_active'] ?? true) === false) {
                continue;
            }
            $price = $item['price_wmr'] ?? $item['price_rub'] ?? null;
            $price = is_numeric($price) ? (float) $price : null;
            if ($price === null || $price <= 0) {
                continue;
            }
            $name = (string) ($item['name'] ?? 'Товар');
            $searchTitle = (string) ($item['search_title'] ?? '');
            $contentTypeId = isset($item['content_type_id']) ? (int) $item['content_type_id'] : null;
            $slug = $item['url'] ?? null;
            $id = $item['id_goods'] ?? $item['id'] ?? null;
            $raw = $slug
                ? 'https://ggsel.net/catalog/product/'.$slug
                : ($id ? 'https://ggsel.net/catalog/product/'.$id : 'https://ggsel.net/');
            if ($partnerId !== '') {
                $raw .= (str_contains($raw, '?') ? '&' : '?').'ai='.$partnerId;
            }
            $offers[] = [
                'title' => $searchTitle !== '' ? "{$name} — {$searchTitle}" : $name,
                'url' => $raw,
                'price_rub' => round($price, 2),
                'sales' => (int) ($item['cnt_sell'] ?? 0),
                'seller_name' => $item['seller_name'] ?? null,
                'kind' => Classifier::ggsel($contentTypeId, $name, $searchTitle),
            ];
        }

        return [$offers, $total ?: count($offers), null];
    }
}
