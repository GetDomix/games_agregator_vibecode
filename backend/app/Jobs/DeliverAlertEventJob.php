<?php

namespace App\Jobs;

use App\Models\AlertDelivery;
use App\Models\AlertEvent;
use App\Services\Telegram\TelegramNotifyService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class DeliverAlertEventJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 4;

    public int $timeout = 30;

    public int $uniqueFor = 3600;

    public function __construct(public readonly int $eventId) {}

    public function uniqueId(): string
    {
        return 'alert-delivery:'.$this->eventId;
    }

    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function handle(TelegramNotifyService $telegram): void
    {
        $event = AlertEvent::query()->with(['delivery', 'user', 'game', 'alert'])->findOrFail($this->eventId);
        $delivery = $event->delivery;

        if (! $delivery || in_array($delivery->status, [AlertDelivery::STATUS_SENT, AlertDelivery::STATUS_SKIPPED], true)) {
            Log::info('alert_delivery_replay_skipped', [
                'event_id' => $event->id,
                'delivery_id' => $delivery?->id,
                'status' => $delivery?->status,
            ]);

            return;
        }

        if (! $event->user->telegram_chat_id) {
            $delivery->update(['status' => AlertDelivery::STATUS_SKIPPED, 'last_error' => 'Telegram chat is not linked']);
            Log::warning('alert_delivery_skipped', [
                'event_id' => $event->id,
                'delivery_id' => $delivery->id,
                'attempts' => $delivery->attempts,
                'reason' => 'telegram_chat_not_linked',
            ]);

            return;
        }
        if (! $event->user->radar_enabled) {
            $delivery->update(['status' => AlertDelivery::STATUS_SKIPPED, 'last_error' => 'Telegram radar is disabled']);
            Log::warning('alert_delivery_skipped', [
                'event_id' => $event->id,
                'delivery_id' => $delivery->id,
                'attempts' => $delivery->attempts,
                'reason' => 'telegram_radar_disabled',
            ]);

            return;
        }

        $delivery->increment('attempts');
        $delivery->forceFill(['status' => AlertDelivery::STATUS_PENDING, 'last_attempt_at' => now(), 'last_error' => null])->save();
        if (! $telegram->sendMessage($event->user->telegram_chat_id, $this->message($event))) {
            throw new \RuntimeException('Telegram delivery was not accepted');
        }

        $delivery->update(['status' => AlertDelivery::STATUS_SENT, 'sent_at' => now(), 'last_error' => null]);
        Log::info('alert_delivery_sent', [
            'event_id' => $event->id,
            'delivery_id' => $delivery->id,
            'attempts' => $delivery->attempts,
        ]);
    }

    public function failed(\Throwable $exception): void
    {
        AlertDelivery::query()->where('alert_event_id', $this->eventId)->update([
            'status' => AlertDelivery::STATUS_FAILED,
            'last_error' => Str::limit($exception->getMessage(), 1000),
            'updated_at' => now(),
        ]);
        $delivery = AlertDelivery::query()->where('alert_event_id', $this->eventId)->first();
        Log::error('alert_delivery_failed', [
            'event_id' => $this->eventId,
            'delivery_id' => $delivery?->id,
            'attempts' => $delivery?->attempts,
            'error_class' => $exception::class,
        ]);
    }

    private function message(AlertEvent $event): string
    {
        $name = htmlspecialchars($event->game->name, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $source = match ($event->source) {
            'steam' => 'Steam',
            'plati' => 'Plati.Market',
            'ggsel' => 'GGsel',
            default => $event->source,
        };
        $kind = match ($event->offer_kind) {
            'official' => 'официальная версия', 'key' => 'ключ', 'gift' => 'гифт',
            'account' => 'аккаунт', 'rent' => 'аренда', default => $event->offer_kind,
        };
        $price = number_format($event->offer_price_rub, 0, '.', ' ');
        $link = $event->offer_url ? '<a href="'.htmlspecialchars($event->offer_url, ENT_QUOTES | ENT_HTML5, 'UTF-8').'">Открыть предложение</a>' : '';

        $headline = $event->alert?->cycle !== $event->alert_cycle ? 'Ценовой сигнал сработал' : match ($event->alert?->condition_type) {
            'discount_percent' => 'Скидка Steam достигла '.(int) ($event->alert?->target_value ?? 0).'%',
            'new_low' => 'Новый минимум с начала наблюдений',
            default => 'Цель цены достигнута',
        };

        return "🎯 <b>{$headline}</b>\n{$name}\n{$source} · {$kind}: <b>{$price} ₽</b>\n{$link}";
    }
}
