<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CurrentGamePrice;
use App\Services\Pricing\SteamNewReleasesService;
use App\Services\Pricing\SteamWeeklyDealsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PlatiRouletteController extends Controller
{
    public function __invoke(
        Request $request,
        SteamNewReleasesService $newReleases,
        SteamWeeklyDealsService $weeklyDeals,
    ): JsonResponse
    {
        $exclude = collect(explode(',', (string) $request->query('exclude', '')))
            ->map(fn (string $appid): int => (int) $appid)
            ->filter()
            ->take(40)
            ->all();

        $pool = [];
        $offers = CurrentGamePrice::query()
            ->where('source', 'plati')
            ->whereNotNull('cheapest_offer_url')
            ->where('offer_count', '>', 0)
            ->with('game')
            ->orderBy('min_price_rub')
            ->limit(250)
            ->get();

        foreach ($offers as $offer) {
            if (! $offer->game) {
                continue;
            }
            $appid = (int) $offer->game->steam_appid;
            if (isset($pool[$appid])) {
                continue;
            }
            $pool[$appid] = [
                'appid' => $appid,
                'name' => $offer->game->name,
                'header_image' => $offer->game->header_image,
                'offer_kind' => $offer->offer_kind,
                'price_rub' => (float) $offer->min_price_rub,
                'url' => $offer->cheapest_offer_url,
                'source' => 'saved_plati',
            ];
        }

        // Внешняя витрина расширяет рулетку ещё до того, как сайт накопил
        // собственный каталог. Если для игры уже есть Plati-лот, он остаётся
        // приоритетным; иначе пользователь попадёт в обычное сравнение цен.
        try {
            $showcaseItems = array_merge(
                $newReleases->releases()['items'] ?? [],
                $weeklyDeals->deals()['items'] ?? [],
            );
            foreach ($showcaseItems as $item) {
                $appid = (int) ($item['appid'] ?? 0);
                $name = trim((string) ($item['name'] ?? ''));
                if ($appid < 1 || $name === '' || isset($pool[$appid])) {
                    continue;
                }
                $pool[$appid] = [
                    'appid' => $appid,
                    'name' => $name,
                    'header_image' => $item['header_image'] ?? null,
                    'offer_kind' => null,
                    'price_rub' => null,
                    'url' => null,
                    'source' => 'steam_showcase',
                ];
            }
        } catch (\Throwable) {
            // Сохранённые Plati-лоты всё равно остаются рабочим резервом.
        }

        $available = array_values(array_filter(
            $pool,
            static fn (array $item): bool => ! in_array($item['appid'], $exclude, true),
        ));
        if ($available === []) {
            $available = array_values($pool);
        }

        if ($available === []) {
            return response()->json(['detail' => 'Подходящих предложений пока нет'], 404);
        }

        return response()->json($available[array_rand($available)]);
    }
}
