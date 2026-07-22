<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TelegramNotifyService
{
    public function sendMessage(string|int $chatId, string $text, ?string $parseMode = 'HTML'): bool
    {
        $token = (string) config('gpa.telegram_bot_token', '');
        if ($token === '') {
            Log::warning('telegram: no TELEGRAM_BOT_TOKEN');

            return false;
        }

        try {
            $resp = Http::timeout(20)
                ->asForm()
                ->post("https://api.telegram.org/bot{$token}/sendMessage", [
                    'chat_id' => $chatId,
                    'text' => $text,
                    'parse_mode' => $parseMode,
                    'disable_web_page_preview' => true,
                ]);
            if (! $resp->successful()) {
                Log::warning('telegram send failed', ['body' => $resp->body()]);

                return false;
            }

            return true;
        } catch (\Throwable $e) {
            Log::warning('telegram send exception', ['e' => $e->getMessage()]);

            return false;
        }
    }
}
