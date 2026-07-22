<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'max:72'],
            'display_name' => ['nullable', 'string', 'max:80'],
        ]);

        $email = mb_strtolower(trim($data['email']));
        $name = trim((string) ($data['display_name'] ?? ''));
        if ($name === '') {
            $name = explode('@', $email)[0] ?: 'Игрок';
        }

        $user = User::create([
            'name' => $name,
            'display_name' => $name,
            'email' => $email,
            'password' => $data['password'],
            'last_login_at' => now(),
        ]);

        $token = $user->createToken('web')->plainTextToken;

        return response()->json([
            'access_token' => $token,
            'token_type' => 'bearer',
            'user' => $user->toPublicArray(),
        ], 201);
    }

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::query()->where('email', mb_strtolower(trim($data['email'])))->first();
        if (! $user || ! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['Неверный email или пароль'],
            ]);
        }

        $user->forceFill(['last_login_at' => now()])->save();
        $token = $user->createToken('web')->plainTextToken;

        return response()->json([
            'access_token' => $token,
            'token_type' => 'bearer',
            'user' => $user->toPublicArray(),
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json($request->user()->toPublicArray());
    }

    public function updateMe(Request $request): JsonResponse
    {
        $data = $request->validate([
            'display_name' => ['required', 'string', 'max:80'],
        ]);
        $name = trim($data['display_name']);
        if ($name === '') {
            throw ValidationException::withMessages([
                'display_name' => ['Имя не может быть пустым'],
            ]);
        }
        $user = $request->user();
        $user->forceFill([
            'display_name' => $name,
            'name' => $name,
        ])->save();

        return response()->json($user->toPublicArray());
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()?->delete();

        return response()->json(['ok' => true]);
    }
}
