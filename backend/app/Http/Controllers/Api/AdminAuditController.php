<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Admin\AdminAuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminAuditController extends Controller
{
    public function __invoke(Request $request, AdminAuditService $audit): JsonResponse
    {
        $data = $request->validate([
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        return response()->json($audit->paginateFor(
            $request->user(),
            (int) ($data['per_page'] ?? 25),
        ));
    }
}
