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
        'case_size', 'cartons', 'packs', 'loose', 'units_per_pack', 'rate', 'discount_percent', 'note',
        'expires_on', 'batch_no',
    ];

    protected function casts(): array
    {
        return [
            'expires_on' => 'date',
            'case_size' => 'integer',
            'cartons' => 'integer',
            'packs' => 'integer',
            'loose' => 'integer',
            'units_per_pack' => 'integer',
            'rate' => MoneyCast::class,
            'discount_percent' => 'decimal:3',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** The catalogue product this line is counted against, when it names one. */
    public function product(): BelongsTo
    {
        return $this->belongsTo(CompanyProduct::class, 'company_product_id');
    }

    public function orderLine(): BelongsTo
    {
        return $this->belongsTo(OrderLine::class);
    }

    /** True when it was checked and nothing had come. */
    public function isMissing(): bool
    {
        return $this->packs === 0 && (int) $this->loose === 0;
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
        // Part of a pack: "2 packs and 10 loose", or "10 loose" alone.
        if ((int) $this->loose > 0) {
            $loose = number_format($this->loose).' loose';

            return $this->packs > 0
                ? number_format($this->packs).' '.($this->packs === 1 ? 'pack' : 'packs').' and '.$loose
                : $loose;
        }

        if ($this->cartons === null) {
            return number_format($this->packs).' '.($this->packs === 1 ? 'pack' : 'packs');
        }

        return $this->cartons.' '.($this->cartons === 1 ? 'carton' : 'cartons')
            .' ('.number_format($this->packs).' packs)';
    }

    public function gross(): Money
    {
        $whole = $this->rate->times($this->packs);

        if ((int) $this->loose < 1 || (int) $this->units_per_pack < 2) {
            return $whole;
        }

        // The rate is for a pack, so loose pieces are that share of one —
        // rounded half up to the paisa, once, here.
        $share = bcdiv(bcmul($this->rate->toDecimal(), (string) $this->loose, 6), (string) $this->units_per_pack, 6);

        return $whole->plus(Money::of(bcadd($share, '0.005', 2)));
    }

    public function amount(?string $orderPercent = null): Money
    {
        $percent = $this->discount_percent ?? $orderPercent;

        return $percent === null || (float) $percent == 0.0
            ? $this->gross()
            : $this->gross()->minus($this->gross()->percent($percent));
    }
}
