<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ExternalIdentity;
use App\Models\TelegramLinkCode;
use App\Models\User;
use App\Services\DueGameRefreshDispatcher;
use App\Services\TelegramAccountMergeService;
use App\Services\TelegramOidcService;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

class TelegramController extends Controller
{
    public function oidcBegin(Request $request, TelegramOidcService $oidc): JsonResponse
    {
        return response()->json(['authorization_url' => $oidc->begin($request->user()?->id)]);
    }

    public function oidcCallback(
        Request $request,
        TelegramOidcService $oidc,
        TelegramAccountMergeService $merge
    ): Response
    {
        $data = $request->validate([
            'state' => ['required', 'string'],
            'code' => ['required', 'string'],
        ]);

        try {
            $result = $oidc->callback($data['state'], $data['code'], $merge);
            $token = $result['user']->createToken('telegram-oidc')->plainTextToken;

            return $this->oidcPopupResponse([
                'type' => 'igroscan:telegram-oidc',
                'ok' => true,
                'access_token' => $token,
                'user' => $result['user']->toPublicArray(),
                'created' => $result['created'],
                'merged' => $result['merged'],
                'report' => $result['report'],
            ]);
        } catch (\Throwable $e) {
            report($e);

            return $this->oidcPopupResponse([
                'type' => 'igroscan:telegram-oidc',
                'ok' => false,
                'detail' => 'Не удалось подтвердить вход Telegram',
            ], 422);
        }
    }

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
            'identity_linked' => ExternalIdentity::query()
                ->where('user_id', $u->id)
                ->where('provider', 'telegram')
                ->exists(),
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

        $user = $row->user;
        $otherUser = User::query()
            ->where('telegram_chat_id', $data['chat_id'])
            ->whereKeyNot($user->id)
            ->exists();

        if ($otherUser) {
            return response()->json(['detail' => 'Этот Telegram уже привязан к другому аккаунту'], 409);
        }

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

    /** Compatibility trigger: dispatches canonical due work, never calls stores. */
    public function runScan(Request $request): JsonResponse
    {
        $this->assertServiceToken($request);
        $count = app(DueGameRefreshDispatcher::class)->dispatch();

        return response()->json(['ok' => true, 'queued' => $count]);
    }

    private function assertServiceToken(Request $request): void
    {
        $expected = (string) config('gpa.radar_service_token', '');
        $got = (string) $request->header('X-Radar-Token', '');
        if ($expected === '' || ! hash_equals($expected, $got)) {
            throw new HttpResponseException(response()->json(['detail' => 'Unauthorized'], 401));
        }
    }

    /** Return the OIDC result to the website popup without putting a token in a URL. */
    private function oidcPopupResponse(array $payload, int $status = 200): Response
    {
        $json = json_encode($payload, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR);
        $html = '<!doctype html><html lang="ru"><meta charset="utf-8"><title>Игроскан</title>'
            .'<script>const payload='.$json.';if(window.opener){window.opener.postMessage(payload,window.location.origin);window.close();}</script>'
            .'<p>Telegram подтверждён. Это окно можно закрыть.</p></html>';

        return response($html, $status)->header('Content-Type', 'text/html; charset=UTF-8');
    }
}
