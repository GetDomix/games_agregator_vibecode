<?php

namespace Tests\Unit;

use App\Services\Telegram\TelegramNotifyService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TelegramNotifyServiceTest extends TestCase
{
    public function test_it_sends_the_expected_telegram_form_request(): void
    {
        config()->set('gpa.telegram_bot_token', 'test-token');
        Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true])]);

        $this->assertTrue(app(TelegramNotifyService::class)->sendMessage('123', '<b>Deal</b>'));

        Http::assertSent(function (Request $request): bool {
            return $request->url() === 'https://api.telegram.org/bottest-token/sendMessage'
                && $request['chat_id'] === '123'
                && $request['text'] === '<b>Deal</b>'
                && $request['parse_mode'] === 'HTML'
                && $request['disable_web_page_preview'] === true
                && str_starts_with((string) $request->header('Content-Type')[0], 'application/x-www-form-urlencoded');
        });
    }

    public function test_it_does_not_send_without_a_token(): void
    {
        config()->set('gpa.telegram_bot_token', '');
        Http::fake();

        $this->assertFalse(app(TelegramNotifyService::class)->sendMessage('123', 'Deal'));
        Http::assertNothingSent();
    }

    public function test_it_returns_false_for_non_successful_response(): void
    {
        config()->set('gpa.telegram_bot_token', 'test-token');
        Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => false], 429)]);

        $this->assertFalse(app(TelegramNotifyService::class)->sendMessage('123', 'Deal'));
    }

    public function test_it_returns_false_for_transport_exception(): void
    {
        config()->set('gpa.telegram_bot_token', 'test-token');
        Http::fake(fn () => throw new \RuntimeException('transport unavailable'));

        $this->assertFalse(app(TelegramNotifyService::class)->sendMessage('123', 'Deal'));
    }
}
