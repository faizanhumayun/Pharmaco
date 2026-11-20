<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Support\Concerns\BelongsToBusiness;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One item on a bill, priced as it was at the moment of sale. */
class PosBillLine extends Model
{
    use BelongsToBusiness;

    protected $fillable = [
        'business_id', 'pos_bill_id', 'company_product_id', 'name',
        'quantity', 'loose_quantity', 'units_per_pack', 'short_by', 'unit_price', 'unit_cost', 'line_total',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'loose_quantity' => 'integer',
            'units_per_pack' => 'integer',
            'short_by' => 'integer',
            'unit_price' => MoneyCast::class,
            'unit_cost' => MoneyCast::class,
            'line_total' => MoneyCast::class,
        ];
    }

    public function bill(): BelongsTo
    {
        return $this->belongsTo(PosBill::class, 'pos_bill_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(CompanyProduct::class, 'company_product_id');
    }

    public function cost(): Money
    {
        return $this->unit_cost->times($this->quantity)
            ->plus(self::piece($this->unit_cost, $this->units_per_pack)->times($this->loose_quantity));
    }

    /**
     * What one loose piece goes for when the pack goes for this much.
     *
     * Rounded to the paisa, half up — the same rule on the bill, on a return
     * and in the cost, so the three can never disagree by a rounding crumb.
     */
    public static function piece(Money $perPack, int $unitsPerPack): Money
    {
        if ($unitsPerPack <= 1) {
            return $perPack;
        }

        return Money::of(bcdiv(bcadd(bcdiv($perPack->toDecimal(), (string) $unitsPerPack, 6), '0.005', 6), '1', 2));
    }

    /** The price of one loose piece on this line. */
    public function piecePrice(): Money
    {
        return self::piece($this->unit_price, $this->units_per_pack);
    }

    /** Everything on the line counted in pieces. */
    public function pieces(): int
    {
        return $this->quantity * max(1, $this->units_per_pack) + $this->loose_quantity;
    }

    /** "2 + 3 loose" — how the quantity reads on paper. */
    public function quantityLabel(): string
    {
        if ($this->loose_quantity === 0) {
            return (string) $this->quantity;
        }

        return $this->quantity > 0
            ? "{$this->quantity} + {$this->loose_quantity} loose"
            : "{$this->loose_quantity} loose";
    }

    /** Sold beyond what the books held — the count needs looking at. */
    public function soldShort(): bool
    {
        return $this->short_by > 0;
    }
}
