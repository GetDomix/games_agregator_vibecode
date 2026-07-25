<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FavoriteAlertScope extends Model
{
    protected $fillable = ['favorite_alert_id', 'source', 'offer_kind'];

    public function alert(): BelongsTo
    {
        return $this->belongsTo(FavoriteAlert::class, 'favorite_alert_id');
    }
}
