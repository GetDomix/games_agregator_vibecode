<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Favorite extends Model
{
    protected $fillable = [
        'user_id',
        'appid',
        'game_name',
        'header_image',
        'notes',
        'target_price_rub',
        'last_steam_price_rub',
    ];

    protected function casts(): array
    {
        return [
            'target_price_rub' => 'float',
            'last_steam_price_rub' => 'float',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function priceBelowTarget(): bool
    {
        return $this->target_price_rub !== null
            && $this->last_steam_price_rub !== null
            && $this->last_steam_price_rub <= $this->target_price_rub;
    }

    public function toApiArray(): array
    {
        return [
            'id' => $this->id,
            'appid' => (int) $this->appid,
            'game_name' => $this->game_name,
            'header_image' => $this->header_image,
            'notes' => $this->notes,
            'target_price_rub' => $this->target_price_rub,
            'last_steam_price_rub' => $this->last_steam_price_rub,
            'price_below_target' => $this->priceBelowTarget(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
