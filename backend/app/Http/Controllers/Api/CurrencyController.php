<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Pricing\ExchangeRateService;
use Illuminate\Http\JsonResponse;

class CurrencyController extends Controller
{
    public function __invoke(ExchangeRateService $exchangeRates): JsonResponse
    {
        return response()->json([
            'base' => 'RUB',
            'rates' => $exchangeRates->availableRates(),
        ]);
    }
}
