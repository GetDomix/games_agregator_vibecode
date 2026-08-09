<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Admin\AdminRoleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AdminTeamController extends Controller
{
    public function index(): JsonResponse
    {
        $configuredEmails = $this->configuredOwnerEmails();
        $users = User::query()
            ->where(function ($query) use ($configuredEmails) {
                $query->whereIn('admin_role', User::ADMIN_ROLES);
                if ($configuredEmails !== []) {
                    $query->orWhereIn(DB::raw('LOWER(email)'), $configuredEmails);
                }
            })
            ->orderBy('id')
            ->get()
            ->map(fn (User $user) => $user->toPublicArray())
            ->values();

        return response()->json(['items' => $users]);
    }

    public function update(Request $request, User $user, AdminRoleService $roles): JsonResponse
    {
        $unknownKeys = array_diff(array_keys($request->all()), ['role', 'current_password']);
        if ($unknownKeys !== []) {
            throw ValidationException::withMessages([
                'request' => ['Запрос содержит неподдерживаемые поля'],
            ]);
        }

        $data = $request->validate([
            'role' => ['required', 'string', Rule::in([User::ROLE_USER, ...User::ADMIN_ROLES])],
            'current_password' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);
        $updated = $roles->changeRole(
            $request->user(),
            $user,
            $data['role'],
            $data['current_password'] ?? null,
        );

        return response()->json(['ok' => true, 'user' => $updated->toPublicArray()]);
    }

    /** @return list<string> */
    private function configuredOwnerEmails(): array
    {
        return array_values(array_unique(array_filter(array_map(
            fn (string $email) => mb_strtolower(trim($email)),
            explode(',', (string) config('gpa.admin_emails', '')),
        ))));
    }
}
