<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SearchHistory extends Model
{
    protected $fillable = [
        'user_id',
        'query',
        'appid',
        'game_name',
        'header_image',
        'steam_price_rub',
        'plati_min_rub',
        'ggsel_min_rub',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'meta' => 'array',
            'steam_price_rub' => 'float',
            'plati_min_rub' => 'float',
            'ggsel_min_rub' => 'float',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function toApiArray(): array
    {
        return [
            'id' => $this->id,
            'query' => $this->query,
            'appid' => $this->appid ? (int) $this->appid : null,
            'game_name' => $this->game_name,
            'header_image' => $this->header_image,
            'steam_price_rub' => $this->steam_price_rub,
            'plati_min_rub' => $this->plati_min_rub,
            'ggsel_min_rub' => $this->ggsel_min_rub,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
