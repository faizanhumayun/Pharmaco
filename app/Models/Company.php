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

    public function productImports(): HasMany
    {
        return $this->hasMany(CompanyProductImport::class)->latest('id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
