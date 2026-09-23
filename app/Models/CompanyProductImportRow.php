<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of a price list, as read and as confirmed.
 *
 * cells is what came out of the PDF and is never altered; values is what the
 * operator agreed to. Keeping both is what makes a wrong price traceable back
 * to the line on the page that caused it.
 */
class CompanyProductImportRow extends Model
{
    protected $fillable = [
        'company_product_import_id', 'page_no', 'line_no',
        'cells', 'values', 'issues', 'included', 'edited',
    ];

    protected function casts(): array
    {
        return [
            'cells' => 'array',
            'values' => 'array',
            'issues' => 'array',
            'included' => 'boolean',
            'edited' => 'boolean',
        ];
    }

    public function import(): BelongsTo
    {
        return $this->belongsTo(CompanyProductImport::class, 'company_product_import_id');
    }

    public function scopeIncluded(Builder $query): Builder
    {
        return $query->where('included', true);
    }

    public function value(string $field): ?string
    {
        $value = $this->values[$field] ?? null;

        return $value === null || $value === '' ? null : (string) $value;
    }

    /** @return array<int, array<string, string>> */
    public function issuesFor(string $field): array
    {
        return array_values(array_filter(
            $this->issues ?? [],
            fn (array $issue) => ($issue['field'] ?? null) === $field,
        ));
    }

    public function hasError(): bool
    {
        foreach ($this->issues ?? [] as $issue) {
            if (($issue['level'] ?? '') === 'error') {
                return true;
            }
        }

        return false;
    }

    public function hasWarning(): bool
    {
        foreach ($this->issues ?? [] as $issue) {
            if (($issue['level'] ?? '') === 'warning') {
                return true;
            }
        }

        return false;
    }

    /** The row as it was read, for showing beneath a cell a person is doubting. */
    public function rawText(): string
    {
        return trim(implode('  ', array_filter($this->cells ?? [], fn ($c) => trim((string) $c) !== '')));
    }
}
