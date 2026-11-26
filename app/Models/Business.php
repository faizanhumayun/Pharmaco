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
        'receipt_format', 'accent', 'receipt_footer', 'pos_net_bill', 'pos_print', 'pos_balance', 'expiry_alert_months',
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
            'accent' => \App\Enums\AccentColour::class,
            'pos_net_bill' => 'boolean',
            'pos_print' => 'boolean',
            'pos_balance' => 'boolean',
            'status' => BusinessStatus::class,
            'opening_date' => 'date',
            'locked_through_date' => 'date',
        ];
    }

    /** Company stock audits: one company's rack counted in one go. */
    public function companyAudits(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(CompanyAudit::class);
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
    /**
     * Whether this is a read-only copy of a business that really lives on a
     * shop's own computer. Its books are replaced whole each time the shop
     * sends them, so nothing may be entered into it here.
     */
    public function isShopCopy(): bool
    {
        return $this->mirror_id !== null;
    }

    /**
     * Whether nothing may be entered into this copy here. True of a shop's
     * copy at all times but one: while the back office has paused the shop's
     * computer to fix something, the books are entered here instead, and go
     * back to the shop when the fix is done.
     */
    public function isReadOnlyCopy(): bool
    {
        return $this->isShopCopy() && ! ($this->shopMirror?->isOffice() ?? false);
    }

    public function shopMirror(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(ShopMirror::class, 'mirror_id');
    }

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
    /**
     * Its stock counts.
     *
     * Needed by the scoped route binding as much as by anything reading them:
     * without it a count could be reached through another business's URL, which
     * is exactly what the scoping is there to prevent.
     */
    public function stockCounts(): HasMany
    {
        return $this->hasMany(StockCount::class);
    }

    /**
     * How this business counts one of something.
     *
     * Not a setting: a distributor moves whole packs and cartons, and a
     * pharmacy breaks them open and sells singles. It follows from the kind of
     * business, so there is nothing to choose and nothing to get wrong.
     */
    public function unit(): StockUnit
    {
        return StockUnit::defaultFor($this->business_type);
    }

    /**
     * Whether this business sells at the price printed on the pack.
     *
     * A pharmacy does: the patient pays the MRP, and anything less is a
     * discount given at the counter. So a pharmacy keeps no selling price of
     * its own — its products have an MRP and a purchase rate, and that is all.
     *
     * A distributor does not: it sells to pharmacies at a trade price below
     * the MRP, and needs both figures on every product.
     */
    public function sellsAtMrp(): bool
    {
        return $this->business_type === BusinessType::Pharmacy;
    }

    /**
     * Whether this business opens packs and sells what is inside.
     *
     * A pharmacy does: five tablets out of a strip of ten, and the other five
     * stay on the shelf as loose stock. A distributor never does — it sells
     * the strip, the box, the carton, whole.
     */
    public function sellsLoose(): bool
    {
        return $this->business_type === BusinessType::Pharmacy;
    }

    /**
     * How far ahead expiring stock is flagged, in months.
     *
     * Null when the business has asked not to be warned at all, which is a
     * different answer from zero.
     */
    public function expiryAlertMonths(): ?int
    {
        return $this->expiry_alert_months === null ? null : (int) $this->expiry_alert_months;
    }

    /** The date beyond which a batch is not yet worth flagging. */
    public function expiryHorizon(): ?\Illuminate\Support\Carbon
    {
        $months = $this->expiryAlertMonths();

        return $months === null ? null : $this->today()->copy()->addMonths($months)->endOfDay();
    }

    /** The colour of this business's screens — its own choice, or the usual one. */
    public function accentColour(): \App\Enums\AccentColour
    {
        return $this->accent ?? \App\Enums\AccentColour::defaultFor($this->business_type);
    }

    /** The line a till slip ends on when the business has not written its own. */
    public const DEFAULT_RECEIPT_FOOTER = 'Thank you — get well soon.';

    /**
     * What a till slip ends on, as HTML: the business's own lines, or the
     * usual one. Cleaned again on the way out, so nothing but the editor's
     * own few tags can reach a bill whatever is in the column.
     */
    public function receiptFooter(?string $usual = null): string
    {
        return \App\Support\SlipText::clean($this->receipt_footer) ?? e($usual ?? self::DEFAULT_RECEIPT_FOOTER);
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
