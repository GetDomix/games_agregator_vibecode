<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class AlertEvent extends Model
{
    protected $fillable = [
        'favorite_alert_id', 'alert_cycle', 'user_id', 'favorite_id', 'game_id', 'source', 'offer_kind',
        'offer_price_rub', 'offer_title', 'offer_url', 'observed_at',
    ];

    protected function casts(): array
    {
        return ['offer_price_rub' => 'float', 'observed_at' => 'datetime'];
    }

    public function alert(): BelongsTo
    {
        return $this->belongsTo(FavoriteAlert::class, 'favorite_alert_id');
    }

    public function favorite(): BelongsTo
    {
        return $this->belongsTo(Favorite::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }

    public function delivery(): HasOne
    {
        return $this->hasOne(AlertDelivery::class);
    }

    public function siteNotification(): HasOne
    {
        return $this->hasOne(SiteNotification::class);
    }
}
