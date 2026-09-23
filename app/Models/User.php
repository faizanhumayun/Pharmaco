<?php

namespace App\Models;

use App\Enums\BusinessRole;
use App\Enums\Permission;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    use HasFactory, HasRoles, Notifiable;

    protected $fillable = [
        'name', 'email', 'password', 'is_platform_admin', 'is_active',
    ];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'is_platform_admin' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function businesses(): BelongsToMany
    {
        return $this->belongsToMany(Business::class)
            ->using(BusinessUser::class)
            ->withPivot(['role', 'is_active'])
            ->withTimestamps();
    }

    /** Businesses this user may actually act in right now. */
    public function activeBusinesses(): BelongsToMany
    {
        return $this->businesses()
            ->wherePivot('is_active', true)
            ->where('businesses.status', '!=', 'archived');
    }

    public function createdBusinesses(): HasMany
    {
        return $this->hasMany(Business::class, 'created_by');
    }

    public function scopePlatformAdmins(Builder $query): Builder
    {
        return $query->where('is_platform_admin', true);
    }

    public function isPlatformAdmin(): bool
    {
        return $this->is_platform_admin === true;
    }

    public function belongsToBusiness(Business|int $business): bool
    {
        $id = $business instanceof Business ? $business->id : $business;

        return $this->activeBusinesses()->where('businesses.id', $id)->exists();
    }

    public function roleIn(Business|int $business): ?BusinessRole
    {
        $id = $business instanceof Business ? $business->id : $business;

        $membership = $this->activeBusinesses()->where('businesses.id', $id)->first();

        return $membership?->pivot->role;
    }

    public function isOwnerOf(Business|int $business): bool
    {
        return $this->roleIn($business) === BusinessRole::Owner;
    }

    public function isOperatorOf(Business|int $business): bool
    {
        return $this->roleIn($business) === BusinessRole::Operator;
    }

    /**
     * Whether this person's role in the business grants the permission.
     *
     * The one question every business policy asks. Read from the role's
     * bundle in Permission::forRoles() — the definition itself — so a new
     * role or a regranted permission takes effect without anything else
     * changing. No active membership means no permission, whatever the role.
     */
    public function hasBusinessPermission(Business|int $business, Permission $permission): bool
    {
        $role = $this->roleIn($business);

        return $role !== null
            && in_array($permission->value, Permission::forRoles()[$role->value] ?? [], true);
    }

    /**
     * Where the user lands after signing in. Platform admins go to the
     * back office; everyone else goes to their business.
     */
    public function homeRoute(): string
    {
        if ($this->isPlatformAdmin()) {
            return route('admin.dashboard');
        }

        $business = $this->activeBusinesses()->first();

        return $business
            ? route('businesses.show', $business)
            : route('no-business');
    }
}
