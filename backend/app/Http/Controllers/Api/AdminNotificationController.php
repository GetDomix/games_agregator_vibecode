<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Admin\AdminAuditService;
use App\Services\Notifications\SiteNotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AdminNotificationController extends Controller
{
    public function store(
        Request $request,
        SiteNotificationService $notifications,
        AdminAuditService $audit,
    ): JsonResponse {
        $unknownKeys = array_diff(array_keys($request->all()), ['title', 'body', 'priority']);
        if ($unknownKeys !== []) {
            throw ValidationException::withMessages([
                'request' => ['Запрос содержит неподдерживаемые поля'],
            ]);
        }

        $data = $request->validate([
            'title' => ['required', 'string', 'min:3', 'max:120'],
            'body' => ['required', 'string', 'min:1', 'max:1000'],
            'priority' => ['required', Rule::in(['info', 'important', 'update'])],
        ]);
        $result = $notifications->broadcast($request->user(), $data);
        $notification = $result['notification'];
        $audit->record($request->user(), 'notification.broadcast_sent', 'site_notification', (string) $notification->id, [
            'title' => $notification->title,
            'priority' => $data['priority'],
            'audience_count' => $result['audience_count'],
        ]);

        return response()->json([
            'id' => $notification->id,
            'audience_count' => $result['audience_count'],
            'published_at' => $notification->published_at?->toIso8601String(),
        ], 201);
    }
}
