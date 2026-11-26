<?php

namespace App\Models;

use App\Support\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Company extends Model
{
    use BelongsToBusiness;

    protected $fillable = [
        'business_id', 'name', 'code', 'account_id', 'credit_days', 'contact', 'phone', 'is_active',
    ];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /** The company's catalogue, as last imported from its price list. */
    public function products(): HasMany
    {
        return $this->hasMany(CompanyProduct::class);
    }

    /** Every bill from this company, as entered on its day. */
    public function purchaseLines(): HasMany
    {
        return $this->hasMany(PurchaseLine::class);
    }

    public function productImports(): HasMany
    {
        return $this->hasMany(CompanyProductImport::class)->latest('id');
    }

    /** How a company's code is written: CMP-0001, CMP-0002 … */
    public static function formatCode(int $number): string
    {
        return 'CMP-'.str_pad((string) $number, 4, '0', STR_PAD_LEFT);
    }

    /**
     * The next free code for a business's new company. Codes are made here,
     * never typed, and the database refuses a second company with the same
     * one. Call it inside the transaction that creates the company, after
     * locking the business row, so two people adding at once cannot get the same.
     */
    public static function nextCode(Business $business): string
    {
        $highest = static::forBusiness($business)
            ->where('code', 'like', 'CMP-%')
            ->pluck('code')
            ->map(fn (string $code) => ctype_digit(substr($code, 4)) ? (int) substr($code, 4) : 0)
            ->max() ?? 0;

        return static::formatCode($highest + 1);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
