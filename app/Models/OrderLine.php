<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Domain\Products\ProductLabel;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One product on an order form, with its description and rate as they stood
 * when the order was written. Nothing here is read back out of the catalogue.
 */
class OrderLine extends Model
{
    protected $fillable = [
        'order_id', 'company_product_id', 'position', 'code', 'brand_name', 'generic_name',
        'strength', 'dosage_form', 'pack_size', 'case_size', 'cartons', 'packs',
        'rate', 'discount_percent',
    ];

    protected function casts(): array
    {
        return [
            'case_size' => 'integer',
            'cartons' => 'integer',
            'packs' => 'integer',
            'rate' => MoneyCast::class,
            'discount_percent' => 'decimal:3',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** The catalogue entry this came from, when it is still there. */
    public function product(): BelongsTo
    {
        return $this->belongsTo(CompanyProduct::class, 'company_product_id');
    }

    /** Rate times packs, before any discount. */
    public function gross(): Money
    {
        return $this->rate->times($this->packs);
    }

    /**
     * The discount that applies here: this line's own rate when it names one,
     * otherwise whatever the order committed to across the board.
     */
    public function discountPercent(?string $orderPercent = null): ?string
    {
        $percent = $this->discount_percent ?? $orderPercent ?? $this->order?->discount_percent;

        return $percent === null || (float) $percent == 0.0 ? null : (string) $percent;
    }

    public function discountAmount(?string $orderPercent = null): Money
    {
        $percent = $this->discountPercent($orderPercent);

        return $percent === null ? Money::zero() : $this->gross()->percent($percent);
    }

    /** What this line actually comes to. */
    public function amount(?string $orderPercent = null): Money
    {
        return $this->gross()->minus($this->discountAmount($orderPercent));
    }

    /** True when the discount here is not the one the rest of the order gets. */
    public function hasOwnDiscount(): bool
    {
        return $this->discount_percent !== null;
    }

    public function label(): string
    {
        return ProductLabel::make($this->brand_name, $this->strength, $this->dosage_form);
    }

    /** "4 cartons (384 packs)", or just the packs when there is no carton size. */
    public function quantityLabel(): string
    {
        if ($this->cartons === null) {
            return $this->packs.' '.str($this->packs === 1 ? 'pack' : 'packs');
        }

        return $this->cartons.' '.($this->cartons === 1 ? 'carton' : 'cartons')
            .' ('.number_format($this->packs).' packs)';
    }
}
