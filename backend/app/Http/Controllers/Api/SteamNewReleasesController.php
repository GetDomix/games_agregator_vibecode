<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Pricing\SteamNewReleasesService;
use Illuminate\Http\JsonResponse;

class SteamNewReleasesController extends Controller
{
    public function __invoke(SteamNewReleasesService $releases): JsonResponse
    {
        try {
            return response()->json($releases->releases());
        } catch (\Throwable $exception) {
            report($exception);

            return response()->json(['generated_at' => now()->toIso8601String(), 'currency' => 'RUB', 'items' => []]);
        }
    }
}
