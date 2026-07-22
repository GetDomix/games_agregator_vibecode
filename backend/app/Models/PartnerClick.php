<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PartnerClick extends Model
{
    protected $fillable = [
        'user_id',
        'marketplace',
        'url',
        'appid',
        'query',
        'price_rub',
        'client_ip',
    ];

    protected function casts(): array
    {
        return [
            'price_rub' => 'float',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
