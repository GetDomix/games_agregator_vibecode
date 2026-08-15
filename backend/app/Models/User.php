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

    public const ROLE_USER = 'user';

    public const ROLE_ADMIN = 'admin';

    public const ROLE_OWNER = 'owner';

    public const ADMIN_ROLES = [self::ROLE_ADMIN, self::ROLE_OWNER];

    protected $fillable = [
        'name',
        'display_name',
        'email',
        'password',
        'last_login_at',
        'admin_role',
        'telegram_chat_id',
        'telegram_username',
        'telegram_linked_at',
        'radar_enabled',
        'alert_prefs',
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
            'password' => 'hashed',
            'telegram_linked_at' => 'datetime',
            'radar_enabled' => 'boolean',
            'alert_prefs' => 'array',
        ];
    }

    public function effectiveAdminRole(): string
    {
        return $this->isServerManagedOwner() ? self::ROLE_OWNER : ($this->admin_role ?: self::ROLE_USER);
    }

    public function canAccessAdmin(): bool
    {
        return in_array($this->effectiveAdminRole(), self::ADMIN_ROLES, true);
    }

    public function canManageAdminTeam(): bool
    {
        return $this->effectiveAdminRole() === self::ROLE_OWNER;
    }

    public function isServerManagedOwner(): bool
    {
        if ($this->email === null || $this->email === '') {
            return false;
        }
        $list = (string) config('gpa.admin_emails', '');
        if ($list === '') {
            return false;
        }
        $emails = array_map(fn ($e) => mb_strtolower(trim($e)), explode(',', $list));

        return in_array(mb_strtolower($this->email), $emails, true);
    }

    public function favorites(): HasMany
    {
        return $this->hasMany(Favorite::class);
    }

    public function searchHistories(): HasMany
    {
        return $this->hasMany(SearchHistory::class);
    }

    public function externalIdentities(): HasMany
    {
        return $this->hasMany(ExternalIdentity::class);
    }

    public function toPublicArray(): array
    {
        return [
            'id' => $this->id,
            'email' => $this->email,
            'display_name' => $this->display_name ?: $this->name,
            'admin_role' => $this->effectiveAdminRole(),
            'can_access_admin' => $this->canAccessAdmin(),
            'can_manage_admin_team' => $this->canManageAdminTeam(),
            'telegram_linked' => (bool) $this->telegram_chat_id,
            'radar_enabled' => (bool) ($this->radar_enabled ?? true),
            'alert_prefs' => $this->alert_prefs,
            'created_at' => $this->created_at?->toIso8601String(),
            'last_login_at' => $this->last_login_at?->toIso8601String(),
        ];
    }
}
