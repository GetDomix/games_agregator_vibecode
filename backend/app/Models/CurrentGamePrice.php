<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CurrentGamePrice extends Model
{
    public const OFFER_KIND_OFFICIAL = 'official';

    public const OFFER_KIND_KEY = 'key';

    public const OFFER_KIND_GIFT = 'gift';

    public const OFFER_KIND_ACCOUNT = 'account';

    public const OFFER_KIND_RENT = 'rent';

    public const OFFER_KIND_OTHER = 'other';

    public const OFFER_KINDS = [
        self::OFFER_KIND_OFFICIAL,
        self::OFFER_KIND_KEY,
        self::OFFER_KIND_GIFT,
        self::OFFER_KIND_ACCOUNT,
        self::OFFER_KIND_RENT,
        self::OFFER_KIND_OTHER,
    ];

    protected $fillable = [
        'game_id',
        'source',
        'offer_kind',
        'min_price_rub',
        'avg_price_rub',
        'currency_prices',
        'offer_count',
        'cheapest_offer_title',
        'cheapest_offer_url',
        'popular_offer_title',
        'popular_offer_url',
        'popular_offer_price_rub',
        'popular_offer_sales',
        'discount_percent',
        'price_initial_rub',
        'observed_at',
    ];

    protected function casts(): array
    {
        return [
            'min_price_rub' => 'decimal:2',
            'avg_price_rub' => 'decimal:2',
            'currency_prices' => 'array',
            'offer_count' => 'integer',
            'popular_offer_price_rub' => 'decimal:2',
            'popular_offer_sales' => 'integer',
            'discount_percent' => 'integer',
            'price_initial_rub' => 'float',
            'observed_at' => 'datetime',
        ];
    }

    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }
}
