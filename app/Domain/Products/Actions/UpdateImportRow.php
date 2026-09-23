<?php

namespace App\Domain\Products\Actions;

use App\Domain\Products\RowNormaliser;
use App\Models\CompanyProductImportRow;

/**
 * Records a correction a person made on the review screen.
 *
 * What they typed goes through exactly the same rules the parser's output did,
 * so a hand-typed price is checked as carefully as a read one — and the row is
 * marked edited, which is what stops a later remap from discarding the fix.
 */
class UpdateImportRow
{
    public function __construct(private readonly RowNormaliser $normaliser) {}

    /** @param array<string, string|null> $values */
    public function handle(CompanyProductImportRow $row, array $values, ?bool $included = null): CompanyProductImportRow
    {
        $normalised = $this->normaliser->fromValues($values);

        // A person may include a row the parser set aside as doubtful, and may
        // exclude a clean one. What they may not do is import a row that still
        // has an error in it — those have to be fixed, not waved through.
        $include = $included ?? $normalised['included'];

        $row->forceFill([
            'values' => $normalised['values'],
            'issues' => $normalised['issues'],
            'included' => $include && ! $this->normaliser->hasError($normalised['issues']),
            'edited' => true,
        ])->save();

        return $row;
    }
}
