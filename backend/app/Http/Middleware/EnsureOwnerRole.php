<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureOwnerRole
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($request->user()?->canManageAdminTeam(), 403, 'Доступ только для владельца');

        return $next($request);
    }
}
