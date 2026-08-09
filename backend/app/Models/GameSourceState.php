<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GameSourceState extends Model
{
    public const ERROR_REFRESH_FAILED = 'source_refresh_failed';

    public const SOURCE_STEAM = 'steam';

    public const SOURCE_PLATI = 'plati';

    public const SOURCE_GGSEL = 'ggsel';

    public const SOURCES = [
        self::SOURCE_STEAM,
        self::SOURCE_PLATI,
        self::SOURCE_GGSEL,
    ];

    public const STATUS_PENDING = 'pending';

    public const STATUS_FRESH = 'fresh';

    public const STATUS_STALE = 'stale';

    public const STATUS_FAILED = 'failed';

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_FRESH,
        self::STATUS_STALE,
        self::STATUS_FAILED,
    ];

    protected $fillable = [
        'game_id',
        'source',
        'last_attempt_at',
        'last_success_at',
        'next_refresh_at',
        'status',
        'last_error',
        'consecutive_failures',
    ];

    protected function casts(): array
    {
        return [
            'last_attempt_at' => 'datetime',
            'last_success_at' => 'datetime',
            'next_refresh_at' => 'datetime',
            'consecutive_failures' => 'integer',
        ];
    }

    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }
}
