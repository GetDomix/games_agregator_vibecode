<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Game extends Model
{
    public const RELEASE_STATUS_UNKNOWN = 'unknown';

    public const RELEASE_STATUS_ANNOUNCED = 'announced';

    public const RELEASE_STATUS_RELEASED = 'released';

    public const RELEASE_STATUSES = [
        self::RELEASE_STATUS_UNKNOWN,
        self::RELEASE_STATUS_ANNOUNCED,
        self::RELEASE_STATUS_RELEASED,
    ];

    protected $fillable = [
        'steam_appid',
        'name',
        'header_image',
        'release_status',
        'release_date',
    ];

    protected function casts(): array
    {
        return [
            'steam_appid' => 'integer',
            'release_date' => 'date',
        ];
    }

    public function favorites(): HasMany
    {
        return $this->hasMany(Favorite::class);
    }

    public function sourceStates(): HasMany
    {
        return $this->hasMany(GameSourceState::class);
    }

    public function currentPrices(): HasMany
    {
        return $this->hasMany(CurrentGamePrice::class);
    }

    public function priceObservations(): HasMany
    {
        return $this->hasMany(GamePriceObservation::class);
    }

    public function isReleased(): bool
    {
        return $this->release_status === self::RELEASE_STATUS_RELEASED;
    }
}
