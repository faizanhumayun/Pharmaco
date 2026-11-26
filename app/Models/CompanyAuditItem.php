<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Support\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A product on an audit's sheet, and its latest count there. */
class CompanyAuditItem extends Model
{
    use BelongsToBusiness;

    protected $fillable = [
        'business_id', 'company_audit_id', 'company_product_id', 'stock_count_id', 'unit_cost', 'mrp',
    ];

    protected function casts(): array
    {
        return [
            'unit_cost' => MoneyCast::class,
            'mrp' => MoneyCast::class,
        ];
    }

    public function audit(): BelongsTo
    {
        return $this->belongsTo(CompanyAudit::class, 'company_audit_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(CompanyProduct::class, 'company_product_id');
    }

    public function stockCount(): BelongsTo
    {
        return $this->belongsTo(StockCount::class);
    }
}
