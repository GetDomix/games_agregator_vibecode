<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Favorite;
use App\Models\FavoriteAlert;
use App\Models\Game;
use App\Services\Alerts\FavoriteAlertSettingsService;
use App\Services\Catalog\AggregatorService;
use App\Services\Catalog\StoredPriceSearchService;
use App\Services\Pricing\GameRefreshRequestService;
use App\Services\Telegram\TelegramBotUserService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TelegramBotController extends Controller
{
    public function __construct(
        private readonly TelegramBotUserService $users,
        private readonly StoredPriceSearchService $stored,
        private readonly AggregatorService $aggregator,
    ) {}

    public function session(Request $request): JsonResponse
    {
        $data = $request->validate([
            'telegram_user_id' => ['required', 'string', 'regex:/^\\d{1,32}$/'],
            'chat_id' => ['required', 'string', 'regex:/^-?\\d{1,32}$/'],
            'username' => ['nullable', 'string', 'max:64'],
            'display_name' => ['nullable', 'string', 'max:255'],
        ]);
        $user = $this->users->resolve($data['telegram_user_id'], $data['chat_id'], $data['username'] ?? null, $data['display_name'] ?? null);

        return response()->json([
            'user' => ['display_name' => $user->display_name ?: $user->name, 'telegram_linked' => true],
        ]);
    }

    public function search(Request $request): JsonResponse
    {
        $this->user($request);
        $data = $request->validate(['q' => ['required', 'string', 'min:2', 'max:120']]);
        $query = trim($data['q']);
        $candidates = $this->stored->candidates($query);
        $discovery = false;
        if ($candidates === []) {
            $candidates = $this->aggregator->searchCandidates($query);
            $discovery = true;
        }

        return response()->json(['query' => $query, 'candidates' => $candidates, 'meta' => ['discovery_used' => $discovery]]);
    }

    public function card(Request $request, int $appid, GameRefreshRequestService $refresh): JsonResponse
    {
        $user = $this->user($request);
        $data = $request->validate(['q' => ['nullable', 'string', 'max:200']]);
        $game = Game::query()->where('steam_appid', $appid)->first();
        if (! $game) {
            $game = $refresh->requestUnknown($appid, trim((string) ($data['q'] ?? 'Игра Steam')));
        }
        $result = $this->stored->result($game, trim((string) ($data['q'] ?? $game->name)));
        $favorite = Favorite::query()->where('user_id', $user->id)->where('appid', $appid)->with('alert.scopes')->first();

        return response()->json([
            'card' => $result,
            'favorite' => $favorite?->toApiArray(),
        ]);
    }

    public function favorites(Request $request): JsonResponse
    {
        $items = Favorite::query()->where('user_id', $this->user($request)->id)
            ->orderByDesc('updated_at')->with(['alert.scopes', 'game.sourceStates'])->get()
            ->map->toApiArray()->values();

        return response()->json(['items' => $items, 'total' => $items->count()]);
    }

    public function saveFavorite(Request $request, GameRefreshRequestService $refresh, FavoriteAlertSettingsService $alerts): JsonResponse
    {
        $user = $this->user($request);
        $data = $this->favoriteData($request);
        if (trim($data['game_name']) === '') {
            return response()->json(['detail' => 'Название не может быть пустым'], 422);
        }
        $favorite = Favorite::query()->firstOrNew(['user_id' => $user->id, 'appid' => $data['appid']]);
        if (! $favorite->exists && Favorite::query()->where('user_id', $user->id)->count() >= 200) {
            return response()->json(['detail' => 'Лимит избранного: 200 игр'], 400);
        }
        $shouldSaveAlert = array_key_exists('alert', $data) || (array_key_exists('target_price_rub', $data) && $data['target_price_rub'] !== null);
        $alertData = $data['alert'] ?? [
            'target_value' => array_key_exists('target_price_rub', $data) ? $data['target_price_rub'] : $favorite->target_price_rub,
        ];
        if ($shouldSaveAlert) {
            try {
                $alerts->assertValid($alertData);
            } catch (\InvalidArgumentException $exception) {
                return response()->json(['detail' => $exception->getMessage()], 422);
            }
        }
        $favorite->fill([
            'game_name' => mb_substr(trim($data['game_name']), 0, 200),
            'header_image' => $data['header_image'] ?? $favorite->header_image,
            'target_price_rub' => $data['target_price_rub'] ?? $favorite->target_price_rub,
        ])->save();
        $refresh->linkFavorite($favorite);
        if ($shouldSaveAlert) {
            $alerts->save($favorite, $alertData);
        }

        return response()->json($favorite->fresh()->load(['alert.scopes', 'game.sourceStates'])->toApiArray(), $favorite->wasRecentlyCreated ? 201 : 200);
    }

    public function removeFavorite(Request $request, int $appid): JsonResponse
    {
        $favorite = Favorite::query()->where('user_id', $this->user($request)->id)->where('appid', $appid)->firstOrFail();
        $favorite->delete();

        return response()->json(['ok' => true]);
    }

    public function alerts(Request $request): JsonResponse
    {
        $data = $request->validate(['status' => ['nullable', 'in:active,triggered']]);
        $alerts = FavoriteAlert::query()->whereHas('favorite', fn ($q) => $q->where('user_id', $this->user($request)->id))
            ->when($data['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->with(['favorite', 'scopes', 'event.delivery'])->latest('updated_at')->get()
            ->map(fn (FavoriteAlert $alert) => $this->alertArray($alert))->values();

        return response()->json(['items' => $alerts, 'total' => $alerts->count()]);
    }

    public function rearm(Request $request, int $appid, FavoriteAlertSettingsService $alerts): JsonResponse
    {
        $favorite = Favorite::query()->where('user_id', $this->user($request)->id)->where('appid', $appid)->firstOrFail();
        $alerts->rearm($favorite);

        return response()->json($favorite->fresh()->load(['alert.scopes', 'game.sourceStates'])->toApiArray());
    }

    private function user(Request $request)
    {
        $data = $request->validate(['telegram_user_id' => ['required', 'string', 'regex:/^\\d{1,32}$/']]);

        return $this->users->find($data['telegram_user_id']);
    }

    private function favoriteData(Request $request): array
    {
        return $request->validate([
            'appid' => ['required', 'integer', 'min:1'],
            'game_name' => ['required', 'string', 'max:200'],
            'header_image' => ['nullable', 'string', 'max:500', 'regex:/^https?:\/\//i'],
            'target_price_rub' => ['nullable', 'numeric', 'min:0'],
            'alert' => ['sometimes', 'array'],
            'alert.target_value' => ['nullable', 'numeric', 'min:0'],
            'alert.condition_type' => ['nullable', 'in:target_price,discount_percent,new_low'],
            'alert.scopes' => ['nullable', 'array', 'min:1'],
            'alert.scopes.*.source' => ['required_with:alert.scopes', 'string'],
            'alert.scopes.*.offer_kind' => ['required_with:alert.scopes', 'string'],
        ]);
    }

    private function alertArray(FavoriteAlert $alert): array
    {
        $event = $alert->event;

        return [
            'id' => $alert->id,
            'status' => $alert->status,
            'condition_type' => $alert->condition_type,
            'target_value' => $alert->target_value,
            'triggered_at' => $alert->triggered_at?->toIso8601String(),
            'favorite' => ['appid' => $alert->favorite->appid, 'game_name' => $alert->favorite->game_name],
            'scopes' => $alert->scopes->map(fn ($scope) => ['source' => $scope->source, 'offer_kind' => $scope->offer_kind])->values(),
            'event' => $event ? ['source' => $event->source, 'offer_kind' => $event->offer_kind, 'offer_price_rub' => $event->offer_price_rub, 'offer_title' => $event->offer_title, 'offer_url' => $event->offer_url, 'observed_at' => $event->observed_at?->toIso8601String()] : null,
        ];
    }
}
