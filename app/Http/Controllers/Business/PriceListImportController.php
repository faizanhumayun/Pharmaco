<?php

namespace App\Http\Controllers\Business;

use App\Domain\Products\Actions\CommitProductImport;
use App\Domain\Products\Actions\DiscardProductImport;
use App\Domain\Products\Actions\RemapProductImport;
use App\Domain\Products\Actions\StartProductImport;
use App\Domain\Products\Actions\UpdateImportRow;
use App\Enums\ProductField;
use App\Http\Controllers\Controller;
use App\Http\Requests\Business\RemapPriceListRequest;
use App\Http\Requests\Business\StorePriceListRequest;
use App\Http\Requests\Business\UpdateImportRowRequest;
use App\Models\Business;
use App\Models\Company;
use App\Models\CompanyProductImport;
use App\Models\CompanyProductImportRow;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The price-list import: upload, review, apply.
 *
 * The middle step is the point of the whole thing. A PDF is not data — it is a
 * picture of data — so nothing read out of one is written to the catalogue
 * until a person has seen it next to the line it came from and agreed.
 */
class PriceListImportController extends Controller
{
    private const ROWS_PER_PAGE = 100;

    public function index(Business $business, Company $company): View
    {
        $this->authorize('viewProducts', $business);

        return view('business.companies.imports.index', [
            'business' => $business,
            'company' => $company,
            'imports' => $company->productImports()->with('uploader')->paginate(25),
        ]);
    }

    /**
     * The upload form.
     *
     * Reached two ways: from inside a company, which fixes who sent the list,
     * or from the catalogue, where the company is still an open question and
     * the form asks it. Both land here so there is only one upload screen.
     */
    public function create(Business $business, ?Company $company = null): View
    {
        $this->authorize('importProducts', $business);

        return view('business.companies.imports.create', [
            'business' => $business,
            'company' => $company,
            'companies' => $company ? null : Company::forBusiness($business)->active()->orderBy('name')->get(),
            'draft' => $company?->productImports()->drafts()->first(),
            'lastImport' => $company?->productImports()->where('status', 'committed')->first(),
        ]);
    }

    /**
     * The company stays type-hinted even though it is optional: that hint is
     * what tells the router to resolve {company} to a model on the route that
     * carries one. Without it the binding never happens.
     */
    public function store(
        StorePriceListRequest $request,
        Business $business,
        StartProductImport $action,
        ?Company $company = null,
    ): RedirectResponse {
        $company ??= $request->chosenCompany();

        abort_if($company === null, 404);

        try {
            $import = $action->handle($business, $company, $request->file('file'), $request->user());
        } catch (RuntimeException $e) {
            return back()->withErrors(['file' => $e->getMessage()]);
        }

        return redirect()
            ->route('businesses.companies.imports.show', [$business, $company, $import])
            ->with('status', "Read {$import->rows_detected} rows from {$import->original_filename}. Nothing has been imported yet — check the columns below.");
    }

    public function show(
        Request $request,
        Business $business,
        Company $company,
        CompanyProductImport $productImport,
    ): View {
        $this->authorize('viewProducts', $business);

        $rows = $productImport->rows();

        $counts = [
            'total' => (clone $rows)->count(),
            'included' => (clone $rows)->where('included', true)->count(),
            'excluded' => (clone $rows)->where('included', false)->count(),
        ];

        $filter = $request->query('show', $counts['excluded'] > 0 ? 'problems' : 'all');

        $shape = $this->shape($productImport);

        if ($filter === 'problems') {
            $rows->where('included', false);
        } elseif ($filter === 'included') {
            $rows->where('included', true);
        }

        return view('business.companies.imports.review', [
            'business' => $business,
            'company' => $company,
            'import' => $productImport,
            'map' => $productImport->map(),
            'fields' => ProductField::cases(),
            'fieldOptions' => ProductField::options(),
            'columnCount' => $shape['count'],
            'samples' => $shape['samples'],
            'rows' => $rows->paginate(self::ROWS_PER_PAGE)->withQueryString(),
            'counts' => $counts,
            'filter' => $filter,
        ]);
    }

    /** Re-reads every staged row under a corrected column mapping. */
    public function remap(
        RemapPriceListRequest $request,
        Business $business,
        Company $company,
        CompanyProductImport $productImport,
        RemapProductImport $action,
    ): RedirectResponse {
        abort_unless($productImport->isEditable(), 403);

        $action->handle($productImport, $request->columnMap());

        return back()->with('status', 'Columns remapped. Every row was read again, apart from those you had already corrected by hand.');
    }

    public function updateRow(
        UpdateImportRowRequest $request,
        Business $business,
        Company $company,
        CompanyProductImport $productImport,
        CompanyProductImportRow $row,
        UpdateImportRow $action,
    ): RedirectResponse {
        abort_unless($productImport->isEditable(), 403);

        $row = $action->handle($row, $request->correctedValues(), $request->boolean('included'));

        return back()->with('status', $row->included
            ? "Row {$row->line_no} corrected and will be imported."
            : "Row {$row->line_no} will be left out of this import.");
    }

    public function commit(
        Business $business,
        Company $company,
        CompanyProductImport $productImport,
        CommitProductImport $action,
    ): RedirectResponse {
        $this->authorize('importProducts', $business);

        try {
            $import = $action->handle($productImport, request()->user());
        } catch (RuntimeException $e) {
            return back()->withErrors(['status' => $e->getMessage()]);
        }

        return redirect()
            ->route('businesses.products.index', ['business' => $business, 'company' => $company->id])
            ->with('status', sprintf(
                '%s applied: %d products added, %d updated, %d already current.',
                $import->original_filename,
                $import->products_created,
                $import->products_updated,
                $import->products_unchanged,
            ));
    }

    public function discard(
        Business $business,
        Company $company,
        CompanyProductImport $productImport,
        DiscardProductImport $action,
    ): RedirectResponse {
        $this->authorize('importProducts', $business);

        try {
            $action->handle($productImport);
        } catch (RuntimeException $e) {
            return back()->withErrors(['status' => $e->getMessage()]);
        }

        return redirect()
            ->route('businesses.companies.imports.index', [$business, $company])
            ->with('status', "{$productImport->original_filename} was discarded. Nothing in the catalogue changed.");
    }

    /** The company's own file, so a figure can be checked against its page. */
    public function file(Business $business, Company $company, CompanyProductImport $productImport): StreamedResponse
    {
        $this->authorize('viewProducts', $business);

        abort_if($productImport->stored_path === null, 404);
        abort_unless(Storage::disk('local')->exists($productImport->stored_path), 404);

        return Storage::disk('local')->response(
            $productImport->stored_path,
            $productImport->original_filename,
            ['Content-Type' => 'application/pdf'],
        );
    }

    /**
     * The shape of what was read: how many columns the document turned out to
     * have, and a few real values from each so a person can tell at a glance
     * what a column actually holds.
     *
     * @return array{count: int, samples: array<int, array<int, string>>}
     */
    private function shape(CompanyProductImport $import): array
    {
        $widest = 0;
        $samples = [];

        $import->rows()->select('id', 'company_product_import_id', 'cells')->limit(400)->get()
            ->each(function ($row) use (&$widest, &$samples) {
                $cells = $row->cells ?? [];
                $widest = max($widest, count($cells));

                foreach ($cells as $column => $value) {
                    $value = trim((string) $value);

                    if ($value !== '' && count($samples[$column] ?? []) < 3) {
                        $samples[$column][] = mb_substr($value, 0, 40);
                    }
                }
            });

        ksort($samples);

        return ['count' => max($widest, 1), 'samples' => $samples];
    }
}
