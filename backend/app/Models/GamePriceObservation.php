<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GamePriceObservation extends Model
{
    protected $fillable = [
        'game_id',
        'source',
        'offer_kind',
        'min_price_rub',
        'discount_percent',
        'offer_title',
        'offer_url',
        'offer_sales',
        'observed_at',
    ];

    protected function casts(): array
    {
        return [
            'min_price_rub' => 'decimal:2',
            'discount_percent' => 'integer',
            'offer_sales' => 'integer',
            'observed_at' => 'datetime',
        ];
    }

    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }
}
