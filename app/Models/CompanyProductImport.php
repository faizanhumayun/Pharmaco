<?php

namespace App\Models;

use App\Domain\Products\ColumnMap;
use App\Enums\ImportStatus;
use App\Exceptions\ImmutableRecordException;
use App\Support\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A price-list PDF a company sent, and what was done with it.
 *
 * Kept after it has been applied. It is the document behind every price in the
 * catalogue, and the company's own file stays on disk beside it so a figure
 * can always be checked against the page it came from.
 */
class CompanyProductImport extends Model
{
    use BelongsToBusiness;

    protected $fillable = [
        'business_id', 'company_id', 'business_date', 'original_filename', 'stored_path',
        'file_hash', 'page_count', 'status', 'column_map', 'warnings',
        'rows_detected', 'products_created', 'products_updated', 'products_unchanged',
        'rows_skipped', 'uploaded_by', 'committed_at',
    ];

    protected function casts(): array
    {
        return [
            'business_date' => 'date',
            'status' => ImportStatus::class,
            'column_map' => 'array',
            'warnings' => 'array',
            'committed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // Once applied, the import is the record of what was applied. Its
        // counts and its mapping are not open to revision afterwards.
        static::updating(function (self $import) {
            if ($import->getOriginal('status') === ImportStatus::Committed->value) {
                throw new ImmutableRecordException(
                    'This import has already been applied. Import the company\'s next list rather than changing this one.'
                );
            }
        });
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function rows(): HasMany
    {
        return $this->hasMany(CompanyProductImportRow::class)->orderBy('page_no')->orderBy('line_no');
    }

    public function prices(): HasMany
    {
        return $this->hasMany(CompanyProductPrice::class);
    }

    public function scopeDrafts(Builder $query): Builder
    {
        return $query->where('status', ImportStatus::Draft);
    }

    public function map(): ColumnMap
    {
        return ColumnMap::fromArray($this->column_map ?? []);
    }

    public function isEditable(): bool
    {
        return $this->status->isEditable();
    }
}
