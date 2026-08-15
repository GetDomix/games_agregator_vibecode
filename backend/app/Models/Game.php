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
        'plati_id_cb',
        'plati_catalog_name',
        'plati_catalog_resolved_at',
        'ggsel_category_slug',
        'ggsel_digi_catalog_id',
        'ggsel_category_name',
        'ggsel_catalog_resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'steam_appid' => 'integer',
            'release_date' => 'date',
            'plati_id_cb' => 'integer',
            'plati_catalog_resolved_at' => 'datetime',
            'ggsel_digi_catalog_id' => 'integer',
            'ggsel_catalog_resolved_at' => 'datetime',
        ];
    }

    /** Drop cached Plati/GGsel catalog pointers so the next market refresh re-resolves. */
    public function invalidateMarketplaceCatalogCache(): void
    {
        $this->forceFill([
            'plati_id_cb' => null,
            'plati_catalog_name' => null,
            'plati_catalog_resolved_at' => null,
            'ggsel_category_slug' => null,
            'ggsel_digi_catalog_id' => null,
            'ggsel_category_name' => null,
            'ggsel_catalog_resolved_at' => null,
        ])->save();
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

    public function steamRegionalPrices(): HasMany
    {
        return $this->hasMany(SteamRegionalPrice::class);
    }

    public function isReleased(): bool
    {
        return $this->release_status === self::RELEASE_STATUS_RELEASED;
    }
}
