<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Domain\Products\ProductLabel;
use App\Enums\DosageForm;
use App\Enums\PackType;
use App\Support\Concerns\BelongsToBusiness;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One line of a company's catalogue.
 *
 * The three prices are the whole point of it: mrp is what the patient pays,
 * trade_price what the pharmacy pays, and purchase_rate what this business is
 * billed. They are the current figures — {@see CompanyProductPrice} is the
 * record of how they got here, and nothing rewrites that.
 */
class CompanyProduct extends Model
{
    use BelongsToBusiness;

    protected $fillable = [
        'business_id', 'company_id', 'code', 'brand_name', 'generic_name',
        'strength', 'dosage_form', 'pack_size', 'pack_type',
        'mrp', 'trade_price', 'purchase_rate', 'case_size',
        'match_key', 'is_active', 'first_import_id', 'last_import_id', 'priced_on',
    ];

    protected function casts(): array
    {
        return [
            'mrp' => MoneyCast::class,
            'trade_price' => MoneyCast::class,
            'purchase_rate' => MoneyCast::class,
            'case_size' => 'integer',
            'is_active' => 'boolean',
            'priced_on' => 'date',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function prices(): HasMany
    {
        return $this->hasMany(CompanyProductPrice::class)->orderByDesc('business_date')->orderByDesc('id');
    }

    public function lastImport(): BelongsTo
    {
        return $this->belongsTo(CompanyProductImport::class, 'last_import_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        $term = trim((string) $term);

        if ($term === '') {
            return $query;
        }

        return $query->where(function (Builder $q) use ($term) {
            foreach (['brand_name', 'generic_name', 'code', 'strength', 'pack_size'] as $column) {
                $q->orWhere($column, 'like', '%'.$term.'%');
            }
        });
    }

    /**
     * "Brufen 400mg Tablet" — how a person names the product out loud.
     *
     * The pack size is deliberately not in here: everywhere this is shown, the
     * pack has a column of its own beside it.
     */
    public function label(): string
    {
        return ProductLabel::make($this->brand_name, $this->strength, $this->dosage_form);
    }

    public function dosageForm(): ?DosageForm
    {
        return $this->dosage_form === null ? null : DosageForm::tryFrom($this->dosage_form);
    }

    public function packType(): ?PackType
    {
        return $this->pack_type === null ? null : PackType::tryFrom($this->pack_type);
    }

    /** What the business makes per pack if it sells at trade price. */
    public function marginPerPack(): ?Money
    {
        if ($this->trade_price === null || $this->purchase_rate === null) {
            return null;
        }

        return $this->trade_price->minus($this->purchase_rate);
    }

    /** That margin as a percentage of the trade price, to one decimal place. */
    public function marginPercent(): ?string
    {
        $margin = $this->marginPerPack();

        if ($margin === null || $this->trade_price === null || $this->trade_price->isZero()) {
            return null;
        }

        return bcdiv(bcmul($margin->toDecimal(), '100', 4), $this->trade_price->toDecimal(), 1);
    }

    /** What a full carton costs this business. */
    public function caseCost(): ?Money
    {
        if ($this->purchase_rate === null || $this->case_size === null) {
            return null;
        }

        return $this->purchase_rate->times($this->case_size);
    }
}
