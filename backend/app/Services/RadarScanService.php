<?php

namespace App\Services;

use App\Models\Favorite;
use App\Models\RadarEvent;
use App\Models\User;
use Illuminate\Support\Facades\Log;

class RadarScanService
{
    public function __construct(
        private readonly SteamService $steam,
        private readonly TelegramNotifyService $telegram,
    ) {}

    /**
     * Scan all radar-enabled favorites for linked Telegram users.
     *
     * @return array{scanned:int, events:int, notified:int, errors:int}
     */
    public function run(): array
    {
        $stats = ['scanned' => 0, 'events' => 0, 'notified' => 0, 'errors' => 0];
        $dropPct = max(1, (int) config('gpa.radar_drop_percent', 5));
        $minDropRub = max(1, (float) config('gpa.radar_min_drop_rub', 30));
        $sleepMs = max(0, (int) config('gpa.radar_steam_delay_ms', 400));

        $favs = Favorite::query()
            ->with('user')
            ->where('radar_enabled', true)
            ->whereHas('user', function ($q) {
                $q->where('radar_enabled', true)
                    ->whereNotNull('telegram_chat_id');
            })
            ->orderBy('id')
            ->get();

        foreach ($favs as $fav) {
            /** @var Favorite $fav */
            $user = $fav->user;
            if (! $user instanceof User || ! $user->telegram_chat_id) {
                continue;
            }

            $stats['scanned']++;
            try {
                $details = $this->steam->details((int) $fav->appid, $fav->game_name);
                $newPrice = isset($details['price_rub']) && is_numeric($details['price_rub'])
                    ? (float) $details['price_rub']
                    : null;
                $oldPrice = $fav->last_steam_price_rub !== null ? (float) $fav->last_steam_price_rub : null;

                $fav->last_steam_price_rub = $newPrice;
                if (! empty($details['header_image'])) {
                    $fav->header_image = $details['header_image'];
                }
                if (! empty($details['name'])) {
                    $fav->game_name = mb_substr((string) $details['name'], 0, 200);
                }
                $fav->save();

                if ($newPrice === null) {
                    if ($sleepMs) {
                        usleep($sleepMs * 1000);
                    }
                    continue;
                }

                $kind = null;
                $target = $fav->target_price_rub !== null ? (float) $fav->target_price_rub : null;

                // Target hit (priority)
                if ($target !== null && $newPrice <= $target) {
                    $already = $fav->last_notified_price_rub !== null
                        && (float) $fav->last_notified_price_rub <= $target
                        && $fav->last_notified_at
                        && $fav->last_notified_at->gt(now()->subHours(20));
                    if (! $already) {
                        $kind = 'target_hit';
                    }
                }

                // Steam drop without target (or as secondary if not target)
                if ($kind === null && $oldPrice !== null && $newPrice < $oldPrice - 0.01) {
                    $drop = $oldPrice - $newPrice;
                    $pct = $oldPrice > 0 ? ($drop / $oldPrice) * 100 : 0;
                    if ($drop >= $minDropRub || $pct >= $dropPct) {
                        $kind = 'steam_drop';
                    }
                }

                if ($kind === null) {
                    if ($sleepMs) {
                        usleep($sleepMs * 1000);
                    }
                    continue;
                }

                $event = RadarEvent::create([
                    'user_id' => $user->id,
                    'favorite_id' => $fav->id,
                    'appid' => $fav->appid,
                    'game_name' => $fav->game_name,
                    'kind' => $kind,
                    'old_price_rub' => $oldPrice,
                    'new_price_rub' => $newPrice,
                    'target_price_rub' => $target,
                    'notified' => false,
                    'meta' => ['source' => 'steam'],
                ]);
                $stats['events']++;

                $text = $this->formatMessage($kind, $fav->game_name, (int) $fav->appid, $oldPrice, $newPrice, $target);
                $ok = $this->telegram->sendMessage($user->telegram_chat_id, $text);
                if ($ok) {
                    $event->notified = true;
                    $event->save();
                    $fav->last_notified_price_rub = $newPrice;
                    $fav->last_notified_at = now();
                    $fav->save();
                    $stats['notified']++;
                }
            } catch (\Throwable $e) {
                $stats['errors']++;
                Log::warning('radar scan item failed', ['fav' => $fav->id, 'e' => $e->getMessage()]);
            }

            if ($sleepMs) {
                usleep($sleepMs * 1000);
            }
        }

        return $stats;
    }

    private function formatMessage(
        string $kind,
        string $game,
        int $appid,
        ?float $old,
        float $new,
        ?float $target,
    ): string {
        $gameEsc = htmlspecialchars($game, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $store = "https://store.steampowered.com/app/{$appid}/";
        $newS = number_format($new, 0, '.', ' ');
        $oldS = $old !== null ? number_format($old, 0, '.', ' ') : '—';

        if ($kind === 'target_hit') {
            $tS = $target !== null ? number_format($target, 0, '.', ' ') : '—';

            return "🎯 <b>Радар · цель достигнута</b>\n"
                ."{$gameEsc}\n"
                ."Steam: <b>{$newS} ₽</b> (было {$oldS} ₽)\n"
                ."Твоя цель: {$tS} ₽\n"
                ."<a href=\"{$store}\">Открыть в Steam</a>";
        }

        $pct = ($old && $old > 0) ? round((($old - $new) / $old) * 100, 1) : 0;

        return "📉 <b>Радар · цена Steam упала</b>\n"
            ."{$gameEsc}\n"
            ."{$oldS} ₽ → <b>{$newS} ₽</b> (−{$pct}%)\n"
            ."<a href=\"{$store}\">Открыть в Steam</a>";
    }
}
