<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RadarEvent extends Model
{
    protected $fillable = [
        'user_id',
        'favorite_id',
        'appid',
        'game_name',
        'kind',
        'old_price_rub',
        'new_price_rub',
        'target_price_rub',
        'notified',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'old_price_rub' => 'float',
            'new_price_rub' => 'float',
            'target_price_rub' => 'float',
            'notified' => 'boolean',
            'meta' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function favorite(): BelongsTo
    {
        return $this->belongsTo(Favorite::class);
    }
}
