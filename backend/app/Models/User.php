<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'display_name',
        'email',
        'password',
        'last_login_at',
        'plan',
        'plan_expires_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'plan_expires_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function favorites(): HasMany
    {
        return $this->hasMany(Favorite::class);
    }

    public function searchHistories(): HasMany
    {
        return $this->hasMany(SearchHistory::class);
    }

    /** Active paid plan (pro/unlimited) and not expired. */
    public function hasActivePro(): bool
    {
        $plan = strtolower((string) ($this->plan ?: 'free'));
        if (! in_array($plan, ['pro', 'unlimited'], true)) {
            return false;
        }
        if ($this->plan_expires_at === null) {
            return true;
        }

        return $this->plan_expires_at->isFuture();
    }

    /**
     * Daily search limit for this user, or null for unlimited.
     */
    public function dailySearchLimit(): ?int
    {
        if ($this->hasActivePro()) {
            $pro = config('gpa.pro_searches_per_day');
            if ($pro === null || $pro === '' || (int) $pro <= 0) {
                return null;
            }

            return (int) $pro;
        }

        return (int) config('gpa.free_searches_per_day', 15);
    }

    public function planLabel(): string
    {
        return $this->hasActivePro() ? 'Pro' : 'Free';
    }

    public function toPublicArray(): array
    {
        return [
            'id' => $this->id,
            'email' => $this->email,
            'display_name' => $this->display_name ?: $this->name,
            'plan' => $this->hasActivePro() ? (strtolower((string) $this->plan) === 'unlimited' ? 'unlimited' : 'pro') : 'free',
            'plan_label' => $this->planLabel(),
            'plan_expires_at' => $this->hasActivePro() ? $this->plan_expires_at?->toIso8601String() : null,
            'created_at' => $this->created_at?->toIso8601String(),
            'last_login_at' => $this->last_login_at?->toIso8601String(),
        ];
    }
}
