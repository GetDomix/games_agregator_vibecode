<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class FavoriteAlert extends Model
{
    protected $fillable = ['favorite_id', 'condition_type', 'target_value', 'status', 'cycle', 'triggered_at'];

    protected function casts(): array
    {
        return ['target_value' => 'float', 'cycle' => 'integer', 'triggered_at' => 'datetime'];
    }

    public function favorite(): BelongsTo
    {
        return $this->belongsTo(Favorite::class);
    }

    public function scopes(): HasMany
    {
        return $this->hasMany(FavoriteAlertScope::class);
    }

    public function event(): HasOne
    {
        return $this->hasOne(AlertEvent::class)->latestOfMany('alert_cycle');
    }
}
