<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Domain\Products\ProductLabel;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One product as it actually arrived.
 *
 * Paired with the order line it answers, or standing alone when the company
 * sent something that was never ordered.
 */
class OrderReceiptLine extends Model
{
    protected $fillable = [
        'order_id', 'order_line_id', 'company_product_id', 'position',
        'code', 'brand_name', 'generic_name', 'strength', 'dosage_form', 'pack_size',
        'case_size', 'cartons', 'packs', 'rate', 'discount_percent', 'note',
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

    public function orderLine(): BelongsTo
    {
        return $this->belongsTo(OrderLine::class);
    }

    /** True when it was checked and nothing had come. */
    public function isMissing(): bool
    {
        return $this->packs === 0;
    }

    /** True when this arrived without having been asked for. */
    public function isUnordered(): bool
    {
        return $this->order_line_id === null;
    }

    public function label(): string
    {
        return ProductLabel::make($this->brand_name, $this->strength, $this->dosage_form);
    }

    public function quantityLabel(): string
    {
        if ($this->cartons === null) {
            return number_format($this->packs).' '.($this->packs === 1 ? 'pack' : 'packs');
        }

        return $this->cartons.' '.($this->cartons === 1 ? 'carton' : 'cartons')
            .' ('.number_format($this->packs).' packs)';
    }

    public function gross(): Money
    {
        return $this->rate->times($this->packs);
    }

    public function amount(?string $orderPercent = null): Money
    {
        $percent = $this->discount_percent ?? $orderPercent;

        return $percent === null || (float) $percent == 0.0
            ? $this->gross()
            : $this->gross()->minus($this->gross()->percent($percent));
    }
}
