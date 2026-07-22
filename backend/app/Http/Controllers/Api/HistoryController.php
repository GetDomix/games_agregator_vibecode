<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SearchHistory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class HistoryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $limit = min(max((int) $request->query('limit', 50), 1), 200);
        $q = SearchHistory::query()->where('user_id', $request->user()->id);
        $total = (clone $q)->count();
        $items = $q->orderByDesc('created_at')->limit($limit)->get()->map->toApiArray()->values();

        return response()->json(['items' => $items, 'total' => $total]);
    }

    public function destroyAll(Request $request): Response
    {
        SearchHistory::query()->where('user_id', $request->user()->id)->delete();

        return response()->noContent();
    }

    public function destroy(Request $request, int $id): Response|JsonResponse
    {
        $row = SearchHistory::query()->where('user_id', $request->user()->id)->where('id', $id)->first();
        if (! $row) {
            return response()->json(['detail' => 'Запись не найдена'], 404);
        }
        $row->delete();

        return response()->noContent();
    }
}
