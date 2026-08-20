<?php

namespace App\Models;

use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Auth\MustVerifyEmail as MustVerifyEmailTrait;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use TheNguyen\CMS\Traits\HasCmsRoles;

class User extends Authenticatable implements FilamentUser, MustVerifyEmail
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasCmsRoles, HasFactory, MustVerifyEmailTrait, Notifiable;

    /**
     * Gate admin panel access through the CMS permission system (v1.0.0-beta.3).
     *
     * Super admins always get in; otherwise the "admin.access" permission is
     * required. Before the RBAC system is initialised, cms_can() fails open, so
     * the existing login keeps working on a fresh install. Implementing this
     * contract is also what makes the panel reachable in non-local environments.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        return $this->isSuperAdmin() || cms_can('admin.access', $this);
    }

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        // Account Foundation identity/profile basics (v1.0.0-beta.7.1.15).
        // Generic to every user; customer/business data lives in plugins.
        'username',
        'avatar',
        'phone',
        'bio',
        'timezone',
        // Per-user locale preferences. Independent: the backend UI language
        // (admin_locale, beta.7.1.10), the public-site reading language
        // (frontend_locale, beta.7.1.10) and the content editing language
        // (editing_locale, beta.7.1.10.2).
        'admin_locale',
        'frontend_locale',
        'editing_locale',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            // Frontend Authentication (v1.0.0-beta.7.1.14).
            'frontend_last_login_at' => 'datetime',
            'frontend_session_version' => 'integer',
            'remember_token_rotated_at' => 'datetime',
        ];
    }
}
