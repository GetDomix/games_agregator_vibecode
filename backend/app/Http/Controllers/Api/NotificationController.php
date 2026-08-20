<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SiteNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'after_id' => ['nullable', 'integer', 'min:0'],
            'before_id' => ['nullable', 'integer', 'min:1'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);
        $user = $request->user();
        $afterId = (int) ($data['after_id'] ?? 0);
        $beforeId = (int) ($data['before_id'] ?? 0);
        $limit = (int) ($data['limit'] ?? 40);
        $query = SiteNotification::query()->visibleTo($user);
        $items = (clone $query)
            ->when($afterId > 0, fn ($notifications) => $notifications->where('id', '>', $afterId))
            ->when($beforeId > 0, fn ($notifications) => $notifications->where('id', '<', $beforeId))
            ->orderByDesc('id')
            ->limit($limit + 1)
            ->get();
        $hasMore = $items->count() > $limit;
        if ($hasMore) {
            $items = $items->take($limit);
        }
        $latestId = (int) ((clone $query)->max('id') ?? 0);
        $readThrough = (int) ($user->notifications_read_through_id ?? 0);
        $unreadCount = (clone $query)->where('id', '>', $readThrough)->count();

        return response()->json([
            'items' => $items->map(fn (SiteNotification $notification) => $this->item($notification, $readThrough))->values(),
            'unread_count' => $unreadCount,
            'latest_id' => $latestId,
            'has_more' => $hasMore,
            'next_before_id' => $hasMore ? $items->last()?->id : null,
        ]);
    }

    public function readThrough(Request $request): JsonResponse
    {
        $data = $request->validate(['through_id' => ['required', 'integer', 'min:0']]);
        $user = $request->user();
        $requested = (int) $data['through_id'];
        $latestVisible = (int) (SiteNotification::query()->visibleTo($user)->where('id', '<=', $requested)->max('id') ?? 0);
        $next = max((int) ($user->notifications_read_through_id ?? 0), $latestVisible);
        $user->forceFill(['notifications_read_through_id' => $next])->save();

        return response()->json(['read_through_id' => $next]);
    }

    private function item(SiteNotification $notification, int $readThrough): array
    {
        return [
            'id' => $notification->id,
            'type' => $notification->type,
            'title' => $notification->title,
            'body' => $notification->body,
            'data' => $notification->data ?? [],
            'published_at' => $notification->published_at?->toIso8601String(),
            'read' => $notification->id <= $readThrough,
        ];
    }
}
