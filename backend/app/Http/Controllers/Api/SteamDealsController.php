<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Pricing\SteamWeeklyDealsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SteamDealsController extends Controller
{
    public function __construct(private readonly SteamWeeklyDealsService $deals)
    {
    }

    public function index(Request $request): JsonResponse
    {
        if ($request->boolean('refresh')) {
            return response()->json($this->deals->refresh());
        }

        return response()->json($this->deals->deals());
    }
}
