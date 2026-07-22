<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TelegramLinkCode;
use App\Models\User;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class TelegramController extends Controller
{
    /** Authenticated user creates a one-time link code for the bot. */
    public function createLinkCode(Request $request): JsonResponse
    {
        $user = $request->user();
        TelegramLinkCode::query()
            ->where('user_id', $user->id)
            ->whereNull('used_at')
            ->delete();

        $code = strtoupper(Str::random(8));
        TelegramLinkCode::create([
            'user_id' => $user->id,
            'code' => $code,
            'expires_at' => now()->addMinutes(20),
        ]);

        $bot = (string) config('gpa.telegram_bot_username', '');
        $deep = $bot !== '' ? "https://t.me/{$bot}?start={$code}" : null;

        return response()->json([
            'code' => $code,
            'expires_in_seconds' => 20 * 60,
            'bot_username' => $bot ?: null,
            'deep_link' => $deep,
            'instruction' => $deep
                ? 'Открой ссылку или отправь боту /start '.$code
                : 'Отправь боту команду: /start '.$code,
        ]);
    }

    public function status(Request $request): JsonResponse
    {
        $u = $request->user();

        return response()->json([
            'linked' => (bool) $u->telegram_chat_id,
            'telegram_username' => $u->telegram_username,
            'telegram_linked_at' => $u->telegram_linked_at?->toIso8601String(),
            'radar_enabled' => (bool) $u->radar_enabled,
            'bot_username' => config('gpa.telegram_bot_username') ?: null,
        ]);
    }

    public function updateRadar(Request $request): JsonResponse
    {
        $data = $request->validate([
            'radar_enabled' => ['required', 'boolean'],
        ]);
        $u = $request->user();
        $u->radar_enabled = (bool) $data['radar_enabled'];
        $u->save();

        return response()->json([
            'radar_enabled' => $u->radar_enabled,
        ]);
    }

    public function unlink(Request $request): JsonResponse
    {
        $u = $request->user();
        $u->telegram_chat_id = null;
        $u->telegram_username = null;
        $u->telegram_linked_at = null;
        $u->save();

        return response()->json(['linked' => false]);
    }

    /**
     * Internal: bot binds chat_id using link code.
     * Header: X-Radar-Token: RADAR_SERVICE_TOKEN
     */
    public function bind(Request $request): JsonResponse
    {
        $this->assertServiceToken($request);
        $data = $request->validate([
            'code' => ['required', 'string', 'max:32'],
            'chat_id' => ['required', 'string', 'max:32'],
            'telegram_username' => ['nullable', 'string', 'max:64'],
        ]);

        $code = strtoupper(trim($data['code']));
        $row = TelegramLinkCode::query()
            ->where('code', $code)
            ->whereNull('used_at')
            ->where('expires_at', '>', now())
            ->first();

        if (! $row) {
            return response()->json(['detail' => 'Код недействителен или просрочен'], 404);
        }

        // free chat_id if linked to another account
        User::query()->where('telegram_chat_id', $data['chat_id'])->update([
            'telegram_chat_id' => null,
            'telegram_username' => null,
            'telegram_linked_at' => null,
        ]);

        $user = $row->user;
        $user->telegram_chat_id = (string) $data['chat_id'];
        $user->telegram_username = $data['telegram_username'] ?? null;
        $user->telegram_linked_at = now();
        $user->radar_enabled = true;
        $user->save();

        $row->used_at = now();
        $row->save();

        return response()->json([
            'ok' => true,
            'user_id' => $user->id,
            'display_name' => $user->display_name ?: $user->name,
            'email' => $user->email,
        ]);
    }

    /** Internal trigger for radar scan (optional; also artisan radar:scan). */
    public function runScan(Request $request): JsonResponse
    {
        $this->assertServiceToken($request);
        $stats = app(\App\Services\RadarScanService::class)->run();

        return response()->json(['ok' => true, 'stats' => $stats]);
    }

    private function assertServiceToken(Request $request): void
    {
        $expected = (string) config('gpa.radar_service_token', '');
        $got = (string) $request->header('X-Radar-Token', '');
        if ($expected === '' || ! hash_equals($expected, $got)) {
            throw new HttpResponseException(response()->json(['detail' => 'Unauthorized'], 401));
        }
    }
}
