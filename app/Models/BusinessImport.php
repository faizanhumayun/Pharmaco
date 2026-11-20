<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One upload of an old POS database for one business, from the back office.
 *
 * Platform-scoped on purpose: it is created by the App Owner, who belongs to
 * no business, so it does not use the business scope. Every query names the
 * business itself.
 */
class BusinessImport extends Model
{
    public const PENDING = 'pending';
    public const IMPORTED = 'imported';
    public const DISCARDED = 'discarded';

    protected $fillable = [
        'business_id', 'source', 'original_name', 'path', 'status',
        'options', 'result', 'created_by', 'imported_by', 'imported_at',
    ];

    protected function casts(): array
    {
        return ['options' => 'array', 'result' => 'array', 'imported_at' => 'datetime'];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isPending(): bool
    {
        return $this->status === self::PENDING;
    }
}
