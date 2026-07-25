<?php

namespace Tests\Feature;

use App\Models\Favorite;
use App\Models\FavoriteAlert;
use App\Models\FavoriteAlertScope;
use App\Models\User;
use App\Services\TelegramAccountMergeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TelegramAccountMergeTest extends TestCase
{
    use RefreshDatabase;

    public function test_merge_moves_unique_favorites_and_keeps_website_target(): void
    {
        $site = User::factory()->create();
        $telegram = User::factory()->create([
            'telegram_chat_id' => '123456',
            'telegram_username' => 'radar_user',
            'radar_enabled' => true,
        ]);
        $websiteFavorite = Favorite::query()->create([
            'user_id' => $site->id,
            'appid' => 1,
            'game_name' => 'One',
            'target_price_rub' => 300,
        ]);
        $telegramFavorite = Favorite::query()->create([
            'user_id' => $telegram->id,
            'appid' => 1,
            'game_name' => 'One',
            'target_price_rub' => 100,
        ]);
        $uniqueFavorite = Favorite::query()->create([
            'user_id' => $telegram->id,
            'appid' => 2,
            'game_name' => 'Two',
        ]);

        $websiteAlert = FavoriteAlert::query()->create([
            'favorite_id' => $websiteFavorite->id,
            'condition_type' => 'target_price',
            'target_value' => 300,
            'status' => 'active',
        ]);
        $telegramAlert = FavoriteAlert::query()->create([
            'favorite_id' => $telegramFavorite->id,
            'condition_type' => 'target_price',
            'target_value' => 100,
            'status' => 'active',
        ]);
        FavoriteAlertScope::query()->create([
            'favorite_alert_id' => $websiteAlert->id,
            'source' => 'steam',
            'offer_kind' => 'official',
        ]);
        FavoriteAlertScope::query()->create([
            'favorite_alert_id' => $telegramAlert->id,
            'source' => 'plati',
            'offer_kind' => 'gift',
        ]);

        app(TelegramAccountMergeService::class)->merge($site, $telegram);

        $this->assertDatabaseCount('favorites', 2);
        $this->assertDatabaseHas('favorites', [
            'user_id' => $site->id,
            'appid' => 1,
            'target_price_rub' => 300,
        ]);
        $this->assertDatabaseHas('favorites', ['id' => $uniqueFavorite->id, 'user_id' => $site->id]);
        $this->assertDatabaseHas('favorite_alert_scopes', [
            'favorite_alert_id' => $websiteAlert->id,
            'source' => 'plati',
            'offer_kind' => 'gift',
        ]);
        $this->assertDatabaseHas('users', ['id' => $site->id, 'telegram_chat_id' => '123456']);
        $this->assertDatabaseMissing('users', ['id' => $telegram->id]);
    }
}
