<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PriceSnapshot extends Model
{
    protected $fillable = [
        'user_id',
        'appid',
        'steam_price_rub',
        'market_min_rub',
        'source_query',
        'payload',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'steam_price_rub' => 'float',
            'market_min_rub' => 'float',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
