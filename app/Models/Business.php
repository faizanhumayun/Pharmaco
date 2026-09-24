<?php

namespace App\Models;

use App\Enums\BusinessRole;
use App\Enums\BusinessStatus;
use App\Enums\BusinessType;
use App\Enums\StockUnit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

class Business extends Model
{
    use HasFactory;

    protected $fillable = [
        'name', 'slug', 'business_type', 'status', 'currency', 'timezone', 'stock_unit',
        'receipt_format',
        'address', 'phone', 'email', 'ntn', 'notes', 'created_by',
    ];

    /**
     * Deliberately not fillable: opening_date and locked_through_date are written
     * only by the opening-balance and daily-closing services. Nothing else may
     * move the boundary of what is locked.
     */
    protected function casts(): array
    {
        return [
            'business_type' => BusinessType::class,
            'stock_unit' => StockUnit::class,
            'receipt_format' => \App\Enums\ReceiptFormat::class,
            'status' => BusinessStatus::class,
            'opening_date' => 'date',
            'locked_through_date' => 'date',
        ];
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class)
            ->using(BusinessUser::class)
            ->withPivot(['role', 'is_active'])
            ->withTimestamps();
    }

    public function activeMembers(): BelongsToMany
    {
        return $this->members()->wherePivot('is_active', true);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Bills rung up at the counter. Named for Route::scopeBindings(). */
    public function posBills(): HasMany
    {
        return $this->hasMany(PosBill::class);
    }

    public function openingBalance(): HasOne
    {
        return $this->hasOne(OpeningBalance::class);
    }

    /**
     * Named `entries` so Route::scopeBindings() resolves /b/{business}/daily/{entry}
     * within this business — the second isolation layer, which stops a guessed
     * id loading another tenant's day.
     */
    /**
     * Whether this business is still only setup data.
     *
     * Once a single transaction is posted the business has history, and history
     * is never deleted — it is archived or suspended instead.
     */
    public function hasFinancialHistory(): bool
    {
        return $this->opening_date !== null
            || Transaction::forBusiness($this)->exists();
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    public function closings(): HasMany
    {
        return $this->hasMany(DailyClosing::class);
    }

    public function companies(): HasMany
    {
        return $this->hasMany(Company::class);
    }

    public function pharmacies(): HasMany
    {
        return $this->hasMany(Pharmacy::class);
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(ExpenseCategory::class);
    }

    public function entries(): HasMany
    {
        return $this->hasMany(DailyEntry::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', BusinessStatus::Active);
    }

    public function scopeNotArchived(Builder $query): Builder
    {
        return $query->where('status', '!=', BusinessStatus::Archived);
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function hasMember(User $user): bool
    {
        return $this->members()
            ->where('users.id', $user->id)
            ->wherePivot('is_active', true)
            ->exists();
    }

    public function roleFor(User $user): ?BusinessRole
    {
        $role = $this->members()
            ->where('users.id', $user->id)
            ->wherePivot('is_active', true)
            ->first()?->pivot->role;

        return $role ? BusinessRole::from($role) : null;
    }

    /**
     * A business can record financial activity only once its opening balance is
     * finalized (Phase 4) — otherwise transactions would have no starting point.
     */
    public function acceptsTransactions(): bool
    {
        return $this->status->allowsTransactions() && $this->opening_date !== null;
    }

    public function isDayClosed(Carbon|string $date): bool
    {
        if ($this->locked_through_date === null) {
            return false;
        }

        return Carbon::parse($date)->startOfDay()
            ->lessThanOrEqualTo($this->locked_through_date);
    }

    /** Order forms written for any of this business's companies. */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /** Every company's catalogue, across all of them. */
    public function products(): HasMany
    {
        return $this->hasMany(CompanyProduct::class);
    }

    /**
     * How this business counts stock — packs, or the loose items inside them.
     * Screens ask here rather than saying "packs" and hoping.
     */
    public function unit(): StockUnit
    {
        return $this->stock_unit ?? StockUnit::defaultFor($this->business_type);
    }

    /** What a bill prints on here — the choice made, or the usual one. */
    public function receiptFormat(): \App\Enums\ReceiptFormat
    {
        return $this->receipt_format ?? \App\Enums\ReceiptFormat::defaultFor($this->business_type);
    }

    /** "Today" always means today in the business's own timezone, not the server's. */
    public function today(): Carbon
    {
        return Carbon::now($this->timezone)->startOfDay();
    }
}
