<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;

#[Fillable(['name', 'email', 'username', 'password', 'role', 'is_active'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;

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
            'is_active' => 'boolean',
        ];
    }

    /**
     * Get the user's initials
     */
    public function initials(): string
    {
        return Str::of($this->name)
            ->explode(' ')
            ->take(2)
            ->map(fn ($word) => Str::substr($word, 0, 1))
            ->implode('');
    }

    /**
     * Get the activity logs for the user.
     */
    public function activities(): HasMany
    {
        return $this->hasMany(ActivityLog::class);
    }

    /**
     * Dynamic permission verification mapping.
     */
    public function hasPermission(string $permission): bool
    {
        if ($this->role === 'super_admin') {
            return true;
        }

        $permissions = [
            'admin' => [
                'view_dashboard', 'access_pos', 'view_products', 'add_product', 'edit_product', 'delete_product',
                'view_purchase', 'add_purchase', 'edit_purchase', 'delete_purchase',
                'view_reports', 'export_reports', 'manage_settings', 'manage_users', 'view_profit', 'process_return', 'add_expense', 'view_accounting',
            ],
            'cashier' => [
                'view_dashboard', 'access_pos', 'add_customer', 'view_reports', 'process_return', 'add_expense',
            ],
            'sales_staff' => [
                'view_dashboard', 'access_pos', 'add_customer',
            ],
            'inventory_manager' => [
                'view_dashboard', 'view_products', 'add_product', 'edit_product', 'view_purchase', 'add_purchase', 'edit_purchase',
            ],
            'accountant' => [
                'view_dashboard', 'view_reports', 'view_profit', 'add_expense', 'view_accounting',
            ],
        ];

        $userPermissions = $permissions[$this->role] ?? [];

        return in_array($permission, $userPermissions);
    }

    /**
     * Check if user is super admin or admin.
     */
    public function isAdmin(): bool
    {
        return in_array($this->role, ['super_admin', 'admin']);
    }
}
