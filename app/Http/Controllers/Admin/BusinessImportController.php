<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Migration\ImportOldPos;
use App\Domain\Migration\OldPosException;
use App\Domain\Migration\OldPosPlan;
use App\Domain\Migration\OldPosReader;
use App\Exceptions\LedgerException;
use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\BusinessImport;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Moving a business in from its old POS.
 *
 * Upload the old system's database, look at exactly what would come across,
 * then bring it in. Guarded by the platform.admin middleware on its routes.
 *
 * The uploaded file holds more than is taken from it, so it is kept only
 * between the upload and the decision: importing or discarding removes it.
 */
class BusinessImportController extends Controller
{
    private const DISK = 'local';

    public function create(Business $business): View
    {
        return view('admin.businesses.import.create', [
            'business' => $business,
            'imports' => BusinessImport::where('business_id', $business->id)->with('creator')->latest('id')->get(),
            // What this server will accept in one upload, so a file that is
            // too large is refused on the page with the reason, rather than
            // by PHP with none.
            'uploadLimit' => min($this->bytes((string) ini_get('upload_max_filesize')), $this->bytes((string) ini_get('post_max_size'))),
        ]);
    }

    /** PHP's own shorthand — 2M, 1G — as bytes. Zero or less means no limit. */
    private function bytes(string $value): int
    {
        $number = (int) $value;

        if ($number <= 0) {
            return PHP_INT_MAX;
        }

        return $number * match (strtoupper(substr(trim($value), -1))) {
            'G' => 1024 ** 3,
            'M' => 1024 ** 2,
            'K' => 1024,
            default => 1,
        };
    }

    public function store(Request $request, Business $business, OldPosReader $reader): RedirectResponse
    {
        $request->validate([
            'database' => ['required', 'file', 'max:204800'],
        ], [
            'database.required' => 'Choose the old POS database file.',
            'database.max' => 'That file is larger than 200 MB.',
        ]);

        $file = $request->file('database');

        if (! in_array(strtolower($file->getClientOriginalExtension()), ['mdb', 'accdb', 'json'], true)) {
            return back()->withErrors(['database' => 'That is not an old POS database. It should be a file ending in .mdb, or the .json file converted from one.']);
        }

        $path = $file->storeAs('imports/'.$business->id, Str::uuid().'.mdb', self::DISK);

        try {
            $import = $this->accept($business, $path, $file->getClientOriginalName(), $request, $reader);
        } catch (OldPosException $e) {
            return back()->withErrors(['database' => $e->getMessage()]);
        }

        return redirect()->route('admin.businesses.import.show', [$business, $import]);
    }

    /**
     * The same upload, a piece at a time.
     *
     * An old POS database runs to tens of megabytes and PHP, as it is usually
     * set up, takes two. Rather than depend on how each server is configured,
     * the page sends the file in pieces small enough for any of them, and the
     * last piece puts it together. Answers in JSON: the page is driving.
     */
    public function chunk(Request $request, Business $business, OldPosReader $reader): \Illuminate\Http\JsonResponse
    {
        $data = $request->validate([
            'upload' => ['required', 'uuid'],
            'index' => ['required', 'integer', 'min:0', 'max:999'],
            'total' => ['required', 'integer', 'min:1', 'max:1000'],
            'name' => ['required', 'string', 'max:255'],
            'piece' => ['required', 'file', 'max:1536'],
        ]);

        if (! in_array(strtolower(pathinfo($data['name'], PATHINFO_EXTENSION)), ['mdb', 'accdb', 'json'], true)) {
            return response()->json(['message' => 'That is not an old POS database. It should be a file ending in .mdb, or the .json file converted from one.'], 422);
        }

        $disk = Storage::disk(self::DISK);
        $dir = 'imports/'.$business->id.'/pieces/'.$data['upload'];

        $request->file('piece')->storeAs($dir, str_pad((string) $data['index'], 4, '0', STR_PAD_LEFT), self::DISK);

        if ((int) $data['index'] < (int) $data['total'] - 1) {
            return response()->json(['received' => (int) $data['index'] + 1]);
        }

        // The last piece: every one must be here before they are joined.
        $pieces = collect($disk->files($dir))->sort()->values();

        if ($pieces->count() !== (int) $data['total']) {
            $disk->deleteDirectory($dir);

            return response()->json(['message' => 'Part of the file did not arrive. Upload it again.'], 422);
        }

        $path = 'imports/'.$business->id.'/'.Str::uuid().'.mdb';
        $whole = fopen($disk->path($path), 'wb');

        foreach ($pieces as $piece) {
            $part = fopen($disk->path($piece), 'rb');
            stream_copy_to_stream($part, $whole);
            fclose($part);
        }

        fclose($whole);
        $disk->deleteDirectory($dir);

        try {
            $import = $this->accept($business, $path, $data['name'], $request, $reader);
        } catch (OldPosException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['redirect' => route('admin.businesses.import.show', [$business, $import])]);
    }

    /**
     * Reads the stored file once — so one that cannot be used is refused now
     * rather than on the next page — and records the upload. A file that
     * cannot be read is removed before the refusal is passed on.
     */
    private function accept(Business $business, string $path, string $name, Request $request, OldPosReader $reader): BusinessImport
    {
        try {
            $reader->read(Storage::disk(self::DISK)->path($path));
        } catch (OldPosException $e) {
            Storage::disk(self::DISK)->delete($path);

            throw $e;
        }

        return BusinessImport::create([
            'business_id' => $business->id,
            'source' => 'old-pos-mdb',
            'original_name' => Str::limit(basename($name), 200, ''),
            'path' => $path,
            'status' => BusinessImport::PENDING,
            'created_by' => $request->user()->id,
        ]);
    }

    /** The preview while it is pending; what it did once it is imported. */
    public function show(Business $business, BusinessImport $import, OldPosReader $reader, OldPosPlan $planner): View|RedirectResponse
    {
        abort_if($import->business_id !== $business->id, 404);

        if (! $import->isPending()) {
            return view('admin.businesses.import.done', ['business' => $business, 'import' => $import]);
        }

        try {
            $plan = $planner->build($business, $reader->read(Storage::disk(self::DISK)->path((string) $import->path)));
        } catch (OldPosException $e) {
            return redirect()->route('admin.businesses.import.create', $business)->withErrors(['database' => $e->getMessage()]);
        }

        return view('admin.businesses.import.show', [
            'business' => $business,
            'import' => $import,
            'plan' => $plan,
        ]);
    }

    public function commit(
        Request $request,
        Business $business,
        BusinessImport $import,
        OldPosReader $reader,
        OldPosPlan $planner,
        ImportOldPos $action,
    ): RedirectResponse {
        abort_if($import->business_id !== $business->id, 404);
        abort_unless($import->isPending(), 409, 'That import has already been dealt with.');

        $take = [
            'companies' => $request->boolean('companies'),
            'customers' => $request->boolean('customers'),
            'products' => $request->boolean('products'),
            // Stock belongs to the products that come in with it.
            'stock' => $request->boolean('products') && $request->boolean('stock'),
            // What each customer owed and what each company was owed, chosen
            // apart from the names and apart from each other: an old system's
            // balances are often stale, and the names are still wanted.
            'customer_balances' => $request->boolean('customer_balances'),
            'company_balances' => $request->boolean('company_balances'),
        ];

        if (! in_array(true, $take, true)) {
            return back()->withErrors(['status' => 'Nothing was chosen to import.']);
        }

        try {
            // Planned again from the file at this moment, so what is written
            // is what the business holds now, not what a page showed earlier.
            $plan = $planner->build($business, $reader->read(Storage::disk(self::DISK)->path((string) $import->path)));
            $done = $action->handle($business, $plan, $take, $request->user(), $import->original_name);

            // A business not yet opened gets its opening balance draft filled
            // in from the same file, to be checked and finalised.
            $done['opening_prefilled'] = $action->prefillOpening($business, $plan, $take, $request->user(), $import->original_name);

            // Balances that were asked for but could not be posted yet are
            // kept by name, and brought over when the opening balance is
            // finalised. Balances that were not asked for are not kept at all.
            if (! $business->acceptsTransactions()) {
                $kept = $action->balancesFrom($plan, $take);

                if ($kept['customers'] !== [] || $kept['companies'] !== []) {
                    $done['kept_balances'] = $kept;
                }
            }
        } catch (OldPosException|LedgerException $e) {
            return back()->withErrors(['status' => $e->getMessage()]);
        }

        Storage::disk(self::DISK)->delete((string) $import->path);

        $import->forceFill([
            'status' => BusinessImport::IMPORTED,
            'path' => null,
            'options' => $take,
            'result' => $done,
            'imported_by' => $request->user()->id,
            'imported_at' => now(),
        ])->save();

        return redirect()
            ->route('admin.businesses.import.show', [$business, $import])
            ->with('status', 'Imported. The uploaded file has been removed from the server.');
    }

    /** The balances kept from an import, once the opening balance is finalised. */
    public function balances(Request $request, Business $business, BusinessImport $import, ImportOldPos $action): RedirectResponse
    {
        abort_if($import->business_id !== $business->id, 404);

        $result = $import->result ?? [];
        $kept = $result['kept_balances'] ?? null;

        if ($import->status !== BusinessImport::IMPORTED || ! is_array($kept)) {
            return back()->withErrors(['status' => 'There are no balances waiting from this import.']);
        }

        try {
            $done = $action->bringOverBalances($business, $kept, $request->user(), $import->original_name);
        } catch (LedgerException $e) {
            return back()->withErrors(['status' => $e->getMessage()]);
        }

        unset($result['kept_balances']);
        $import->forceFill(['result' => array_merge($result, $done)])->save();

        return back()->with('status', sprintf(
            'Balances brought over: %d customers and %d companies.', $done['customer_balances'], $done['company_balances']
        ));
    }

    /** Decided against: the file goes, the record of the upload stays. */
    public function destroy(Business $business, BusinessImport $import): RedirectResponse
    {
        abort_if($import->business_id !== $business->id, 404);
        abort_unless($import->isPending(), 409, 'That import has already been dealt with.');

        Storage::disk(self::DISK)->delete((string) $import->path);

        $import->forceFill(['status' => BusinessImport::DISCARDED, 'path' => null])->save();

        return redirect()
            ->route('admin.businesses.import.create', $business)
            ->with('status', 'Discarded. Nothing was imported and the uploaded file has been removed.');
    }
}
