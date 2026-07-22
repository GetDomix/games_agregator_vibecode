<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PartnerClick;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TrackingController extends Controller
{
    public function click(Request $request): JsonResponse
    {
        $data = $request->validate([
            'marketplace' => ['nullable', 'string', 'max:40'],
            'url' => ['required', 'string', 'max:1000'],
            'appid' => ['nullable', 'integer', 'min:1'],
            'query' => ['nullable', 'string', 'max:200'],
            'price_rub' => ['nullable', 'numeric'],
        ]);
        $url = trim($data['url']);
        if ($url === '') {
            return response()->json(['ok' => false, 'id' => null]);
        }
        $row = PartnerClick::create([
            'user_id' => \Illuminate\Support\Facades\Auth::guard('sanctum')->user()?->id,
            'marketplace' => mb_strtolower(trim((string) ($data['marketplace'] ?? 'unknown'))),
            'url' => $url,
            'appid' => $data['appid'] ?? null,
            'query' => $data['query'] ?? null,
            'price_rub' => $data['price_rub'] ?? null,
            'client_ip' => mb_substr((string) $request->ip(), 0, 64),
        ]);

        return response()->json(['ok' => true, 'id' => $row->id]);
    }
}
