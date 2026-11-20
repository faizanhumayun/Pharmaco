<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Support\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One item coming back, at the price and cost it went out at. */
class PosReturnLine extends Model
{
    use BelongsToBusiness;

    protected $fillable = [
        'business_id', 'pos_return_id', 'pos_bill_line_id', 'company_product_id', 'name',
        'quantity', 'loose_quantity', 'units_per_pack', 'unit_price', 'unit_cost', 'line_total',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'loose_quantity' => 'integer',
            'units_per_pack' => 'integer',
            'unit_price' => MoneyCast::class,
            'unit_cost' => MoneyCast::class,
            'line_total' => MoneyCast::class,
        ];
    }

    public function return(): BelongsTo
    {
        return $this->belongsTo(PosReturn::class, 'pos_return_id');
    }

    public function billLine(): BelongsTo
    {
        return $this->belongsTo(PosBillLine::class, 'pos_bill_line_id');
    }

    public function pieces(): int
    {
        return $this->quantity * max(1, $this->units_per_pack) + $this->loose_quantity;
    }

    public function quantityLabel(): string
    {
        if ($this->loose_quantity === 0) {
            return (string) $this->quantity;
        }

        return $this->quantity > 0
            ? "{$this->quantity} + {$this->loose_quantity} loose"
            : "{$this->loose_quantity} loose";
    }
}
