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
        'quantity', 'short_by', 'unit_price', 'unit_cost', 'line_total',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
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
        return $this->unit_cost->times($this->quantity);
    }

    /** Sold beyond what the books held — the count needs looking at. */
    public function soldShort(): bool
    {
        return $this->short_by > 0;
    }
}
