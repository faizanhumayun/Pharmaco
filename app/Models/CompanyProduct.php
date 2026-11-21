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
        'mrp', 'trade_price', 'purchase_rate', 'case_size', 'reorder_level', 'units_per_pack',
        'match_key', 'is_active', 'first_import_id', 'last_import_id', 'priced_on',
    ];

    protected function casts(): array
    {
        return [
            'mrp' => MoneyCast::class,
            'trade_price' => MoneyCast::class,
            'purchase_rate' => MoneyCast::class,
            'case_size' => 'integer',
            'reorder_level' => 'integer',
            'units_per_pack' => 'integer',
            'is_active' => 'boolean',
            'priced_on' => 'date',
        ];
    }

    /**
     * Keeps "pieces in one pack" true to the kind of business.
     *
     * A distributor sells whole packs, so its products are always one piece —
     * whatever was typed. A pharmacy sells what is inside, so a product whose
     * pack size says how many are inside ("1/10", "20's") takes that number
     * when it is created or its pack size changes, unless someone has set a
     * figure of their own. Enforced here, on the model, so an import, a
     * delivery and the edit form cannot each do it differently.
     */
    protected static function booted(): void
    {
        static::saving(function (self $product) {
            static $loose = [];

            $sellsLoose = $loose[$product->business_id] ??= (bool) Business::query()->find($product->business_id)?->sellsLoose();

            if (! $sellsLoose) {
                $product->units_per_pack = 1;

                return;
            }

            $packChanged = ! $product->exists || $product->isDirty('pack_size');
            $setByHand = $product->exists && $product->isDirty('units_per_pack');

            if ($packChanged && ! $setByHand && (int) ($product->units_per_pack ?? 1) <= 1) {
                $product->units_per_pack = \App\Support\PackSize::pieces($product->pack_size);
            }
        });
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

    /** Whether a pack of this can be opened and sold piece by piece. */
    public function sellsLoose(): bool
    {
        return $this->units_per_pack > 1;
    }

    /**
     * How a quantity of this product reads: "141 packs and 4 loose tablets".
     *
     * The whole unit is always called a pack, because that is what the price
     * belongs to — a strip, a bottle, a box. What is left of an opened one is
     * named for what it is: tablets, capsules, sachets, or pieces when the
     * form does not say. A quantity with nothing loose is just its packs.
     */
    public function stockLabel(int $packs, int $loose = 0): string
    {
        $whole = number_format($packs).' '.(abs($packs) === 1 ? 'pack' : 'packs');

        if ($loose === 0) {
            return $whole;
        }

        $noun = $this->pieceNoun();

        $pieces = number_format($loose).' loose '.($loose === 1 ? $noun : $noun.'s');

        return $packs === 0 ? $pieces : $whole.' and '.$pieces;
    }

    /**
     * What one piece out of an opened pack is called: a tablet, a capsule, a
     * sachet, or a piece when the form does not say.
     */
    public function pieceNoun(): string
    {
        // Read from the text as stored, whatever its capitals: imported
        // catalogues write "Tablet", "TABLET" and "tablet" for the same thing.
        $form = mb_strtolower(trim((string) $this->dosage_form));

        return match (true) {
            str_starts_with($form, 'tab') => 'tablet',
            str_starts_with($form, 'cap') => 'capsule',
            str_starts_with($form, 'sachet') => 'sachet',
            default => 'piece',
        };
    }

    public function dosageForm(): ?DosageForm
    {
        return $this->dosage_form === null ? null : DosageForm::tryFrom($this->dosage_form);
    }

    public function packType(): ?PackType
    {
        return $this->pack_type === null ? null : PackType::tryFrom($this->pack_type);
    }

    /**
     * What this business sells the product for.
     *
     * A pharmacy sells at the MRP and nothing else. A distributor sells at
     * its trade price, and the MRP stands in only for a product that has no
     * trade price yet.
     */
    public function sellingPriceFor(Business $business): ?Money
    {
        return $business->sellsAtMrp() ? $this->mrp : ($this->trade_price ?? $this->mrp);
    }

    /**
     * What the business makes per pack at its selling price.
     *
     * Asked with the business, the answer follows how that business sells.
     * Without one it is measured against the trade price, as it always was.
     */
    public function marginPerPack(?Business $business = null): ?Money
    {
        $sells = $business !== null ? $this->sellingPriceFor($business) : $this->trade_price;

        if ($sells === null || $this->purchase_rate === null) {
            return null;
        }

        return $sells->minus($this->purchase_rate);
    }

    /** That margin as a percentage of the selling price, to one decimal place. */
    public function marginPercent(?Business $business = null): ?string
    {
        $margin = $this->marginPerPack($business);
        $sells = $business !== null ? $this->sellingPriceFor($business) : $this->trade_price;

        if ($margin === null || $sells === null || $sells->isZero()) {
            return null;
        }

        return bcdiv(bcmul($margin->toDecimal(), '100', 4), $sells->toDecimal(), 1);
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
