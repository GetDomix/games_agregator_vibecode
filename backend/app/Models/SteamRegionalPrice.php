<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SteamRegionalPrice extends Model
{
    protected $fillable = ['game_id', 'region', 'currency', 'price_amount', 'price_rub', 'observed_at'];

    protected function casts(): array
    {
        return ['price_amount' => 'float', 'price_rub' => 'float', 'observed_at' => 'datetime'];
    }

    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }
}
