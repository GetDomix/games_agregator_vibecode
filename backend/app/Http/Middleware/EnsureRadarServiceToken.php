<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureRadarServiceToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('gpa.radar_service_token', '');
        $received = (string) $request->header('X-Radar-Token', '');

        if ($expected === '' || ! hash_equals($expected, $received)) {
            return response()->json(['detail' => 'Unauthorized'], 401);
        }

        return $next($request);
    }
}
