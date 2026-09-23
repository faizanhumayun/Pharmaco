<?php

namespace App\Domain\Products\Actions;

use App\Domain\Products\ColumnMap;
use App\Domain\Products\ExtractedRow;
use App\Domain\Products\RowNormaliser;
use App\Models\CompanyProductImport;
use Illuminate\Support\Facades\DB;

/**
 * Applies a corrected column mapping to everything already staged.
 *
 * The raw cells are the source, so remapping is always a fresh reading of the
 * file rather than a patch on top of the last one — including for rows a person
 * had edited by hand, which is why those are left alone.
 */
class RemapProductImport
{
    public function __construct(private readonly RowNormaliser $normaliser) {}

    /** @param array<int|string, string|null> $rawMap */
    public function handle(CompanyProductImport $import, array $rawMap): CompanyProductImport
    {
        $map = ColumnMap::fromArray($rawMap);

        return DB::transaction(function () use ($import, $map) {
            $import->forceFill(['column_map' => $map->toArray()])->save();

            $import->rows()->chunkById(500, function ($rows) use ($map) {
                foreach ($rows as $row) {
                    // A row someone has already corrected keeps their figures;
                    // remapping is not permitted to undo a person's work.
                    if ($row->edited) {
                        continue;
                    }

                    $extracted = new ExtractedRow($row->page_no, $row->line_no, $row->cells ?? []);
                    $normalised = $this->normaliser->normalise($map, $extracted);

                    $row->forceFill([
                        'values' => $normalised['values'],
                        'issues' => $normalised['issues'],
                        'included' => $normalised['included'],
                    ])->save();
                }
            });

            return $import->fresh();
        });
    }
}
