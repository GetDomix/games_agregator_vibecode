<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DailySearchQuota;
use App\Models\Favorite;
use App\Models\PriceSnapshot;
use App\Models\SearchHistory;
use App\Models\User;
use App\Services\AggregatorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class PriceController extends Controller
{
    public function __construct(private readonly AggregatorService $aggregator) {}

    public function search(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));
        if ($q === '') {
            return response()->json(['detail' => 'Пустой поисковый запрос'], 400);
        }
        $candidates = $this->aggregator->searchCandidates($q);

        return response()->json(['query' => $q, 'candidates' => $candidates, 'meta' => []]);
    }

    public function quota(Request $request): JsonResponse
    {
        $info = $this->quotaInfo(Auth::guard('sanctum')->user(), $request->ip());

        return response()->json(['quota' => $info]);
    }

    public function prices(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));
        if ($q === '') {
            return response()->json(['detail' => 'Пустой поисковый запрос'], 400);
        }
        $appid = $request->query('appid');
        $appid = $appid !== null && $appid !== '' ? (int) $appid : null;
        if ($appid !== null && $appid < 1) {
            return response()->json(['detail' => 'Некорректный appid'], 422);
        }

        $user = Auth::guard('sanctum')->user();

        try {
            $quota = $this->consumeQuota($user, $request->ip());
        } catch (\RuntimeException $e) {
            return response()->json(['detail' => $e->getMessage()], 429);
        }

        try {
            $result = $this->aggregator->aggregate($q, $appid);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['detail' => 'Ошибка агрегации: '.$e->getMessage()], 400);
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['detail' => 'Ошибка агрегации: '.$e->getMessage()], 502);
        }

        $result['quota'] = $quota;
        if ($user) {
            $result['saved_to_history'] = $this->saveHistory($user, $result);
            $steamAppid = $result['steam']['appid'] ?? $appid;
            $result['is_favorite'] = $steamAppid
                ? Favorite::query()->where('user_id', $user->id)->where('appid', $steamAppid)->exists()
                : false;
        }

        return response()->json($result);
    }

    private function quotaInfo(?User $user, ?string $ip): array
    {
        $isGuest = $user === null;
        $isPro = $user?->hasActivePro() ?? false;
        $limit = $isGuest
            ? (int) config('gpa.guest_searches_per_day', 5)
            : $user->dailySearchLimit();
        $unlimited = ! $isGuest && $limit === null;
        $key = $isGuest ? 'ip:'.($ip ?: 'unknown') : 'user:'.$user->id;
        $day = now()->utc()->format('Y-m-d');
        $row = DailySearchQuota::query()->where('quota_key', $key)->where('day', $day)->first();
        $used = (int) ($row->count ?? 0);

        return [
            'limit' => $unlimited ? null : $limit,
            'used' => $used,
            'remaining' => $unlimited ? null : max(0, (int) $limit - $used),
            'is_guest' => $isGuest,
            'is_pro' => $isPro,
            'unlimited' => $unlimited,
            'plan' => $isGuest ? 'guest' : ($isPro ? 'pro' : 'free'),
            'reset_hint' => $unlimited ? 'без дневного лимита (Pro)' : 'обновится завтра (UTC)',
            'upgrade_hint' => $unlimited
                ? null
                : ($isGuest
                    ? 'Зарегистрируйся — больше поисков. Pro снимает лимит.'
                    : 'Pro — без дневного лимита. Промокод KEYSIGNAL-PRO в кабинете.'),
        ];
    }

    private function consumeQuota(?User $user, ?string $ip): array
    {
        $info = $this->quotaInfo($user, $ip);
        if (! $info['unlimited'] && ($info['remaining'] ?? 0) <= 0) {
            throw new \RuntimeException(
                $info['is_guest']
                    ? "Лимит гостя: {$info['limit']} поисков/день. Зарегистрируйся — больше поисков, или возьми Pro."
                    : "Дневной лимит Free: {$info['limit']} поисков. Оформи Pro (кабинет → тарифы) или промокод KEYSIGNAL-PRO."
            );
        }
        $key = $info['is_guest'] ? 'ip:'.($ip ?: 'unknown') : 'user:'.$user->id;
        $day = now()->utc()->format('Y-m-d');
        $row = DailySearchQuota::query()->firstOrCreate(
            ['quota_key' => $key, 'day' => $day],
            ['count' => 0]
        );
        $row->increment('count');
        $used = (int) $row->fresh()->count;

        return [
            'limit' => $info['limit'],
            'used' => $used,
            'remaining' => $info['unlimited'] ? null : max(0, (int) $info['limit'] - $used),
            'is_guest' => $info['is_guest'],
            'is_pro' => $info['is_pro'],
            'unlimited' => $info['unlimited'],
            'plan' => $info['plan'],
            'reset_hint' => $info['reset_hint'],
            'upgrade_hint' => $info['upgrade_hint'],
        ];
    }

    private function saveHistory(User $user, array $result): bool
    {
        try {
            $platiMin = $this->minFromMarket($result['plati'] ?? []);
            $ggselMin = $this->minFromMarket($result['ggsel'] ?? []);
            $steam = $result['steam'] ?? null;
            SearchHistory::create([
                'user_id' => $user->id,
                'query' => $result['query'],
                'appid' => $steam['appid'] ?? null,
                'game_name' => $steam['name'] ?? $result['query'],
                'header_image' => $steam['header_image'] ?? null,
                'steam_price_rub' => $steam['price_rub'] ?? null,
                'plati_min_rub' => $platiMin,
                'ggsel_min_rub' => $ggselMin,
                'meta' => ['deal' => $result['deal'] ?? null],
            ]);
            $markets = array_filter([$platiMin, $ggselMin], fn ($v) => $v !== null);
            PriceSnapshot::create([
                'user_id' => $user->id,
                'appid' => $steam['appid'] ?? null,
                'steam_price_rub' => $steam['price_rub'] ?? null,
                'market_min_rub' => $markets ? min($markets) : null,
                'source_query' => $result['query'],
                'payload' => ['deal' => $result['deal'] ?? null],
            ]);
            if (! empty($steam['appid']) && isset($steam['price_rub'])) {
                Favorite::query()
                    ->where('user_id', $user->id)
                    ->where('appid', $steam['appid'])
                    ->update(['last_steam_price_rub' => $steam['price_rub']]);
            }
            // soft cap 500
            $count = SearchHistory::query()->where('user_id', $user->id)->count();
            if ($count > 500) {
                $ids = SearchHistory::query()
                    ->where('user_id', $user->id)
                    ->orderBy('created_at')
                    ->limit($count - 500)
                    ->pluck('id');
                SearchHistory::query()->whereIn('id', $ids)->delete();
            }

            return true;
        } catch (\Throwable $e) {
            report($e);

            return false;
        }
    }

    private function minFromMarket(array $stats): ?float
    {
        $mins = [];
        foreach ($stats['by_kind'] ?? [] as $k) {
            if (isset($k['min_price']) && is_numeric($k['min_price'])) {
                $mins[] = (float) $k['min_price'];
            }
        }

        return $mins === [] ? null : min($mins);
    }
}
