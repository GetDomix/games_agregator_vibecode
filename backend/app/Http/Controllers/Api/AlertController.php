<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AlertEvent;
use App\Models\FavoriteAlert;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AlertController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate(['status' => ['nullable', 'in:active,triggered']]);
        $alerts = FavoriteAlert::query()
            ->whereHas('favorite', fn ($query) => $query->where('user_id', $request->user()->id))
            ->when($data['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->with(['favorite', 'scopes', 'event.delivery'])
            ->latest('updated_at')
            ->get();
        $alerts = $alerts
            ->map(fn (FavoriteAlert $alert) => $this->alertArray($alert))
            ->values();

        return response()->json(['items' => $alerts, 'total' => $alerts->count()]);
    }

    public function events(Request $request): JsonResponse
    {
        $data = $request->validate(['per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);
        $events = AlertEvent::query()
            ->where('user_id', $request->user()->id)
            ->with('delivery')
            ->latest('created_at')
            ->paginate((int) ($data['per_page'] ?? 30));

        return response()->json([
            'items' => collect($events->items())->map(fn (AlertEvent $event) => $this->eventArray($event))->values(),
            'meta' => ['page' => $events->currentPage(), 'per_page' => $events->perPage(), 'total' => $events->total()],
        ]);
    }

    private function alertArray(FavoriteAlert $alert): array
    {
        return [
            'id' => $alert->id,
            'status' => $alert->status,
            'condition_type' => $alert->condition_type,
            'target_value' => $alert->target_value,
            'triggered_at' => $alert->triggered_at?->toIso8601String(),
            'favorite' => ['appid' => $alert->favorite->appid, 'game_name' => $alert->favorite->game_name],
            'scopes' => $alert->scopes->map(fn ($scope) => ['source' => $scope->source, 'offer_kind' => $scope->offer_kind])->values(),
            'event' => $alert->event ? $this->eventArray($alert->event) : null,
        ];
    }

    private function eventArray(AlertEvent $event): array
    {
        return [
            'id' => $event->id,
            'source' => $event->source,
            'offer_kind' => $event->offer_kind,
            'offer_price_rub' => $event->offer_price_rub,
            'offer_title' => $event->offer_title,
            'offer_url' => $event->offer_url,
            'observed_at' => $event->observed_at?->toIso8601String(),
            'created_at' => $event->created_at?->toIso8601String(),
            'delivery' => $event->delivery ? [
                'channel' => $event->delivery->channel,
                'status' => $event->delivery->status,
                'attempts' => $event->delivery->attempts,
                'last_attempt_at' => $event->delivery->last_attempt_at?->toIso8601String(),
                'sent_at' => $event->delivery->sent_at?->toIso8601String(),
                'last_error' => $event->delivery->last_error,
            ] : null,
        ];
    }
}
