<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RegionController extends Controller
{
    private const CIS_COUNTRIES = ['AM', 'AZ', 'BY', 'GE', 'KZ', 'KG', 'MD', 'RU', 'TJ', 'TM', 'UA', 'UZ'];

    private const EURO_COUNTRIES = ['AT', 'BE', 'CY', 'DE', 'EE', 'ES', 'FI', 'FR', 'GR', 'IE', 'IT', 'LT', 'LU', 'LV', 'MT', 'NL', 'PT', 'SI', 'SK'];

    public function __invoke(Request $request): JsonResponse
    {
        $country = strtoupper(trim((string) ($request->header('CF-IPCountry')
            ?: $request->header('X-Vercel-IP-Country')
            ?: $request->header('X-Country-Code'))));
        $country = preg_match('/^[A-Z]{2}$/', $country) ? $country : null;
        $isCis = $country !== null && in_array($country, self::CIS_COUNTRIES, true);

        return response()->json([
            'country' => $country,
            'is_cis' => $isCis,
            'locale' => $isCis ? 'ru' : 'en',
            'currency' => match (true) {
                $country === 'RU' => 'RUB',
                $country === 'KZ' => 'KZT',
                $country === 'TR' => 'TRY',
                $country === 'US' => 'USD',
                $country !== null && in_array($country, self::EURO_COUNTRIES, true) => 'EUR',
                default => null,
            },
        ]);
    }
}
