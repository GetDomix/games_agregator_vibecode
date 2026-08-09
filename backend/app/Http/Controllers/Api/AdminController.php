<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Game;
use App\Models\GameSourceState;
use App\Services\Admin\AdminAuditService;
use App\Services\Admin\AdminOverviewService;
use App\Services\Admin\AdminUserDirectoryService;
use App\Services\GameRefreshRequestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AdminController extends Controller
{
    public function overview(Request $request, AdminOverviewService $overview): JsonResponse
    {
        return response()->json($overview->build($request->user()));
    }

    public function users(Request $request, AdminUserDirectoryService $directory): JsonResponse
    {
        $data = $request->validate(['q' => ['nullable', 'string', 'max:120']]);
        $users = $directory->search((string) ($data['q'] ?? ''));

        return response()->json([
            'data' => $users->items(),
            'meta' => [
                'page' => $users->currentPage(),
                'per_page' => $users->perPage(),
                'total' => $users->total(),
            ],
        ]);
    }

    public function refreshGame(
        Request $request,
        int $appid,
        GameRefreshRequestService $refreshes,
        AdminAuditService $audit,
    ): JsonResponse {
        $data = $request->validate([
            'sources' => ['nullable', 'array', 'min:1'],
            'sources.*' => ['string', Rule::in(GameSourceState::SOURCES)],
        ]);
        $game = Game::query()->where('steam_appid', $appid)->firstOrFail();
        $sources = array_values(array_unique($data['sources'] ?? GameSourceState::SOURCES));
        $refreshes->request($game, $sources);
        $audit->record($request->user(), 'game.refresh_requested', 'game', (string) $appid, ['sources' => $sources]);

        return response()->json(['ok' => true, 'appid' => $appid, 'sources' => $sources], 202);
    }
}
