<?php

namespace App\Models;

use App\Casts\MoneyCast;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** How much of a collection went against one bill. */
class CollectionAllocation extends Model
{
    protected $fillable = ['collection_line_id', 'pos_bill_id', 'amount'];

    protected function casts(): array
    {
        return ['amount' => MoneyCast::class];
    }

    public function line(): BelongsTo
    {
        return $this->belongsTo(CollectionLine::class, 'collection_line_id');
    }

    public function bill(): BelongsTo
    {
        return $this->belongsTo(PosBill::class, 'pos_bill_id');
    }
}
