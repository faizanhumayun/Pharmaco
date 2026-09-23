<?php

namespace App\Domain\Products\Actions;

use App\Domain\Products\PriceListParser;
use App\Domain\Products\RowNormaliser;
use App\Enums\ImportStatus;
use App\Models\Business;
use App\Models\Company;
use App\Models\CompanyProductImport;
use App\Models\CompanyProductImportRow;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Takes in a company's price-list PDF and stages what it says.
 *
 * Nothing reaches the catalogue here. The upload is kept, the PDF is read, and
 * every line it produced is written to the staging table with whatever is
 * wrong with it recorded alongside — ready for a person to look at.
 */
class StartProductImport
{
    /** Rows beyond this are not staged; a list this long is not a price list. */
    public const MAX_ROWS = 20000;

    public function __construct(
        private readonly PriceListParser $parser,
        private readonly RowNormaliser $normaliser,
    ) {}

    public function handle(Business $business, Company $company, UploadedFile $file, User $by): CompanyProductImport
    {
        // Stored before it is read, so a file that defeats the parser is still
        // on disk to be looked at rather than lost with the failed request.
        $path = $file->store("price-lists/{$business->id}/{$company->id}", 'local');

        $result = $this->parser->parse(Storage::disk('local')->path($path));

        $warnings = $result->warnings;
        $rows = $result->rows;

        if (count($rows) > self::MAX_ROWS) {
            $warnings[] = 'Only the first '.number_format(self::MAX_ROWS).' rows were staged.';
            $rows = array_slice($rows, 0, self::MAX_ROWS);
        }

        return DB::transaction(function () use ($business, $company, $file, $by, $path, $result, $rows, $warnings) {
            $import = CompanyProductImport::create([
                'business_id' => $business->id,
                'company_id' => $company->id,
                'business_date' => $business->today()->toDateString(),
                'original_filename' => mb_substr($file->getClientOriginalName(), 0, 255),
                'stored_path' => $path,
                'file_hash' => hash_file('sha256', Storage::disk('local')->path($path)),
                'page_count' => $result->pageCount,
                'status' => ImportStatus::Draft,
                'column_map' => $result->map->toArray(),
                'warnings' => $warnings,
                'rows_detected' => count($rows),
                'uploaded_by' => $by->id,
            ]);

            $staged = [];
            $now = now();

            foreach ($rows as $row) {
                $normalised = $this->normaliser->normalise($result->map, $row);

                $staged[] = [
                    'company_product_import_id' => $import->id,
                    'page_no' => $row->page,
                    'line_no' => $row->line,
                    'cells' => json_encode(array_values($row->cells)),
                    'values' => json_encode($normalised['values']),
                    'issues' => json_encode($normalised['issues']),
                    'included' => $normalised['included'],
                    'edited' => false,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            foreach (array_chunk($staged, 500) as $chunk) {
                CompanyProductImportRow::insert($chunk);
            }

            return $import->fresh();
        });
    }
}
