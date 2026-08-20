<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SiteNotification extends Model
{
    public const TYPE_GAME_ALERT = 'game_alert';

    public const TYPE_ADMIN_BROADCAST = 'admin_broadcast';

    protected $fillable = [
        'type', 'recipient_user_id', 'audience_max_user_id', 'alert_event_id', 'actor_id',
        'title', 'body', 'data', 'published_at',
    ];

    protected function casts(): array
    {
        return ['data' => 'array', 'published_at' => 'datetime'];
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_user_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function alertEvent(): BelongsTo
    {
        return $this->belongsTo(AlertEvent::class);
    }

    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return $query
            ->where('published_at', '<=', now())
            ->where(function (Builder $visibility) use ($user): void {
                $visibility
                    ->where('recipient_user_id', $user->id)
                    ->orWhere(function (Builder $broadcast) use ($user): void {
                        $broadcast
                            ->whereNull('recipient_user_id')
                            ->where('audience_max_user_id', '>=', $user->id)
                            ->where('published_at', '>=', $user->created_at);
                    });
            });
    }
}
