<?php

namespace App\Services\Notifications;

use App\Models\AlertEvent;
use App\Models\FavoriteAlert;
use App\Models\SiteNotification;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class SiteNotificationService
{
    public function publishAlert(AlertEvent $event, FavoriteAlert $alert): SiteNotification
    {
        $gameName = $alert->favorite?->game_name ?: $event->game?->name ?: 'Игра';
        $source = match ($event->source) {
            'steam' => 'Steam',
            'plati' => 'Plati.Market',
            'ggsel' => 'GGsel',
            default => $event->source,
        };
        $kind = match ($event->offer_kind) {
            'official' => 'официальная версия',
            'key' => 'ключ',
            'gift' => 'гифт',
            'account' => 'личный аккаунт',
            'shared_account' => 'общий аккаунт / офлайн',
            'rent' => 'аренда',
            default => $event->offer_kind,
        };
        $title = match ($alert->condition_type) {
            'discount_percent' => 'Скидка Steam достигла '.(int) ($alert->target_value ?? 0).'%',
            'new_low' => 'Новый минимум цены',
            default => 'Цель цены достигнута',
        };
        $price = number_format((float) $event->offer_price_rub, 0, '.', ' ');

        return SiteNotification::query()->firstOrCreate(
            ['alert_event_id' => $event->id],
            [
                'type' => SiteNotification::TYPE_GAME_ALERT,
                'recipient_user_id' => $event->user_id,
                'title' => $title,
                'body' => "{$gameName} · {$source} · {$kind}: {$price} ₽",
                'data' => [
                    'appid' => $alert->favorite?->appid,
                    'game_name' => $gameName,
                    'source' => $event->source,
                    'offer_kind' => $event->offer_kind,
                    'offer_price_rub' => (float) $event->offer_price_rub,
                    'offer_url' => $event->offer_url,
                ],
                'published_at' => now(),
            ],
        );
    }

    /** @param array{title:string,body:string,priority:string} $message */
    public function broadcast(User $actor, array $message): array
    {
        return DB::transaction(function () use ($actor, $message): array {
            $audienceMaxId = (int) (User::query()->orderByDesc('id')->lockForUpdate()->first(['id'])?->id ?? 0);
            $audienceCount = $audienceMaxId > 0
                ? User::query()->where('id', '<=', $audienceMaxId)->count()
                : 0;
            $notification = SiteNotification::query()->create([
                'type' => SiteNotification::TYPE_ADMIN_BROADCAST,
                'audience_max_user_id' => $audienceMaxId,
                'actor_id' => $actor->id,
                'title' => $message['title'],
                'body' => $message['body'],
                'data' => ['priority' => $message['priority']],
                'published_at' => now(),
            ]);

            return ['notification' => $notification, 'audience_count' => $audienceCount];
        });
    }
}
