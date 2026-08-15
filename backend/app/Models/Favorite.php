<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Favorite extends Model
{
    protected $fillable = [
        'user_id',
        'game_id',
        'appid',
        'game_name',
        'header_image',
        'notes',
        'target_price_rub',
        'last_steam_price_rub',
        'radar_enabled',
        'last_notified_price_rub',
        'last_notified_at',
    ];

    protected function casts(): array
    {
        return [
            'target_price_rub' => 'float',
            'last_steam_price_rub' => 'float',
            'radar_enabled' => 'boolean',
            'last_notified_price_rub' => 'float',
            'last_notified_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }

    public function alert(): HasOne
    {
        return $this->hasOne(FavoriteAlert::class);
    }

    public function priceBelowTarget(): bool
    {
        return $this->target_price_rub !== null
            && $this->last_steam_price_rub !== null
            && $this->last_steam_price_rub <= $this->target_price_rub;
    }

    public function toApiArray(): array
    {
        $hasAlert = $this->relationLoaded('alert') && $this->alert !== null;
        $isTargetAlert = $hasAlert && $this->alert->condition_type === 'target_price';
        $hasValidTargetAlert = $isTargetAlert && $this->alert->target_value !== null;
        $legacyTarget = $hasAlert ? ($hasValidTargetAlert ? (float) $this->alert->target_value : null) : $this->target_price_rub;
        return [
            'id' => $this->id,
            'appid' => (int) $this->appid,
            'game_name' => $this->game_name,
            'header_image' => $this->header_image,
            'notes' => $this->notes,
            'target_price_rub' => $legacyTarget,
            'suggested_target' => $this->getAttribute('suggested_target'),
            'observed_lows' => $this->getAttribute('observed_lows') ?? [],
            'last_steam_price_rub' => $this->last_steam_price_rub,
            'price_below_target' => $hasAlert
                ? ($hasValidTargetAlert && $this->alert->status === 'triggered')
                : $this->priceBelowTarget(),
            'alert' => $hasAlert ? [
                'condition_type' => $this->alert->condition_type,
                'target_value' => $this->alert->target_value,
                'status' => $this->alert->status,
                'scopes' => $this->alert->relationLoaded('scopes') ? $this->alert->scopes->map(fn ($s) => ['source' => $s->source, 'offer_kind' => $s->offer_kind])->values() : [],
            ] : null,
            'release_status' => $this->relationLoaded('game') ? $this->game?->release_status : null,
            'freshness' => $this->relationLoaded('game') && $this->game?->relationLoaded('sourceStates')
                ? $this->game->sourceStates->map(fn ($state) => [
                    'source' => $state->source,
                    'status' => $state->status,
                    'last_success_at' => $state->last_success_at?->toIso8601String(),
                    'next_refresh_at' => $state->next_refresh_at?->toIso8601String(),
                    'last_error' => $state->last_error === null
                        ? null
                        : GameSourceState::ERROR_REFRESH_FAILED,
                ])->values()
                : [],
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
