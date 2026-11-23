<?php

namespace App\Http\Controllers\Business;

use App\Domain\Orders\Actions\RecordDirectDelivery;
use App\Exceptions\LedgerException;
use App\Domain\Products\Actions\ApplyDeliveryPrices;
use App\Enums\BusinessType;
use App\Domain\Products\Actions\RecordProductPrice;
use App\Domain\Products\Actions\UpdateProduct;
use App\Domain\Products\MatchKey;
use App\Enums\DosageForm;
use App\Support\PackSize;
use Illuminate\Validation\Rule;
use Illuminate\Http\JsonResponse;
use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\Company;
use App\Models\CompanyProduct;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Goods that arrived without an order form.
 *
 * The order was placed on the phone, so there is nothing to check off against
 * — but the van still came. This records what was in it.
 */
class DirectDeliveryController extends Controller
{
    public function create(Business $business): View
    {
        $this->authorize('manageOrders', $business);

        return view('business.stock.receive', [
            'business' => $business,
            'companies' => Company::forBusiness($business)->orderBy('name')->get(['id', 'name']),
            // The whole catalogue, narrowed in the browser once a company is
            // picked — the same trick the counter uses, and for the same
            // reason: a delivery is checked in at speed.
            'products' => CompanyProduct::forBusiness($business)
                ->where('is_active', true)
                ->with('company:id,name')
                ->orderBy('brand_name')
                ->get()
                ->map(fn (CompanyProduct $p) => [
                    'id' => $p->id,
                    'company_id' => $p->company_id,
                    // Whose catalogue it sits in: a search reaches past the
                    // delivering company's own, and says where a product is from.
                    'company' => $p->company?->name,
                    'name' => $p->label(),
                    'code' => $p->code,
                    'rate' => (float) ($p->purchase_rate?->toDecimal() ?? 0),
                    // Preloaded so a product already priced is confirmed rather
                    // than typed again; blank only where nothing is on file.
                    'mrp' => $p->mrp?->toDecimal(),
                    'trade' => $p->trade_price?->toDecimal(),
                    'case' => $p->case_size,
                    // Shown on the line to be confirmed against the carton,
                    // or corrected there and then.
                    'pack' => $p->pack_size,
                ])
                ->values(),
            // What a new product can be: tablet, syrup, injection…
            'forms' => array_map(fn (DosageForm $form) => $form->label(), DosageForm::cases()),
            'today' => $business->today(),
            // Shown on the add-a-product dialog, so the code is known before
            // the product exists rather than discovered after it.
            'nextCode' => $this->nextCode($business),
            // A shop selling to the public must know its own price; a
            // distributor quotes per customer and can fill it in later.
            // A pharmacy sells at the MRP, so the delivery asks for that and
            // has no selling-price column; a distributor is asked its trade price.
            'sellRequired' => $business->sellsAtMrp(),
            'sellLabel' => 'Trade',
            'dayClosed' => $business->isDayClosed($business->today()),
        ]);
    }

    /**
     * A product nobody has imported yet.
     *
     * Companies deliver things that were never on a price list, and the
     * alternative — recording it as a nameless hand-typed line — puts it on the
     * invoice but nowhere in stock, because there is no product to count it
     * against. So it joins the catalogue properly, with the same match key an
     * import would give it, and the next price list recognises it rather than
     * creating a second copy.
     */
    public function storeProduct(Request $request, Business $business, RecordProductPrice $prices): JsonResponse
    {
        $this->authorize('manageOrders', $business);

        $request->merge(['brand_name' => trim(preg_replace('/\s+/', ' ', (string) $request->input('brand_name')) ?? '')]);

        $data = $request->validate([
            'company_id' => ['required', 'integer'],
            'brand_name' => ['required', 'string', 'max:200', 'regex:/\S/'],
            // Tablet, syrup, injection… Asked for, because it decides how the
            // product is named, filtered and — for loose stock — counted.
            'dosage_form' => ['required', Rule::in(array_map(fn (DosageForm $form) => $form->label(), DosageForm::cases()))],
            'code' => ['nullable', 'string', 'max:60'],
            'pack_size' => ['nullable', 'string', 'max:60'],
            'strength' => ['nullable', 'string', 'max:60'],
            'purchase_rate' => ['nullable', 'string'],
            'mrp' => ['nullable', 'string'],
        ]);

        $company = Company::forBusiness($business)->findOrFail($data['company_id']);

        $typed = trim((string) ($data['code'] ?? ''));

        /*
         * The match key is built from the code the company uses, never from one
         * we made up. A generated code identifies the product here; it means
         * nothing on their price list, so keying on it would leave the next
         * import unable to recognise the product and creating a second copy of
         * it. With no code of theirs, the name, strength and pack are the
         * identity — exactly as they are for a product that arrives by import.
         */
        $key = MatchKey::make(
            $typed ?: null,
            $data['brand_name'],
            $data['strength'] ?? null,
            $data['pack_size'] ?? null,
        );

        /*
         * One name, one product. A second product with the same name, strength
         * and type is the first one entered again — under this company or any
         * other — and from then on its stock, its sales and its expiry dates
         * are split between two rows that mean the same thing. Refused, with
         * where the first one is, so it can be searched for instead.
         */
        $same = $this->sameProduct($business, $data['brand_name'], $data['strength'] ?? null, $data['dosage_form']);

        if ($same !== null) {
            throw ValidationException::withMessages(['brand_name' => sprintf(
                '%s is already in the catalogue%s. Search for it instead of adding it again.',
                $same->label(),
                $same->company ? ' under '.$same->company->name : '',
            )]);
        }

        $product = null;

        $product ??= CompanyProduct::create([
            'business_id' => $business->id,
            'company_id' => $company->id,
            'match_key' => $key,
            // Theirs if they have one, ours otherwise — a product with nothing
            // to call it cannot be scanned, searched for, or put on a bill line
            // that anyone can check afterwards.
            'code' => $typed ?: $this->nextCode($business),
            'brand_name' => $data['brand_name'],
            'dosage_form' => $data['dosage_form'],
            'strength' => $data['strength'] ?? null,
            'pack_size' => $data['pack_size'] ?? null,
            'purchase_rate' => ($data['purchase_rate'] ?? null) ?: null,
            'mrp' => ($data['mrp'] ?? null) ?: null,
            'is_active' => true,
        ]);

        // What it costs today, on the record from the moment it exists.
        $prices->handle($product, $request->user());

        return response()->json([
            'next_code' => $this->nextCode($business),
            'id' => $product->id,
            'company_id' => $product->company_id,
            'company' => $company->name,
            'name' => $product->label(),
            'code' => $product->code,
            'rate' => (float) ($product->purchase_rate?->toDecimal() ?? 0),
            'mrp' => $product->mrp?->toDecimal(),
            'trade' => $product->trade_price?->toDecimal(),
            'case' => $product->case_size,
            'pack' => $product->pack_size,
        ]);
    }

    /**
     * A product already in this business's catalogue with the same name,
     * strength and type — whichever company it is filed under. Capitals and
     * extra spaces do not make a name a different one.
     */
    private function sameProduct(Business $business, string $name, ?string $strength, ?string $form): ?CompanyProduct
    {
        $norm = fn (?string $value) => mb_strtolower(trim(preg_replace('/\s+/', ' ', (string) $value) ?? ''));

        return CompanyProduct::forBusiness($business)
            ->with('company:id,name')
            // The column's own comparison ignores capitals; spaces are settled below.
            ->where('brand_name', 'like', str_replace(['%', '_'], ['\\%', '\\_'], trim($name)))
            ->get()
            ->first(fn (CompanyProduct $p) => $norm($p->brand_name) === $norm($name)
                && $norm($p->strength) === $norm($strength)
                && $norm($p->dosage_form) === $norm($form));
    }

    /**
     * Pack sizes corrected on the delivery, written onto the products.
     *
     * The carton in someone's hands is the best evidence there is of what a
     * pack holds, so a size typed here replaces the one on file. A blank box
     * means "no change". Where the business opens packs and the new size
     * says how many pieces it holds, that count follows it — through the same
     * action the product form uses, so the rule that pieces already moved
     * cannot be recounted applies here too.
     *
     * @param  list<array<string, mixed>>  $lines
     */
    private function applyPackSizes(Business $business, array $lines, \App\Models\User $by, UpdateProduct $update): void
    {
        $entered = collect($lines)
            ->mapWithKeys(fn ($line) => [(int) $line['company_product_id'] => trim((string) ($line['pack_size'] ?? ''))])
            ->filter(fn ($pack) => $pack !== '');

        if ($entered->isEmpty()) {
            return;
        }

        $products = CompanyProduct::forBusiness($business)->whereIn('id', $entered->keys())->get();

        foreach ($products as $product) {
            $pack = $entered[$product->id];

            if ($pack === (string) $product->pack_size) {
                continue;
            }

            $changes = ['pack_size' => $pack];
            $pieces = PackSize::pieces($pack);

            if ($business->sellsLoose() && $pieces > 1 && $pieces !== (int) $product->units_per_pack) {
                $changes['units_per_pack'] = $pieces;
            }

            $update->handle($product, $changes, $by);
        }
    }

    /**
     * The next code of our own, as PRD-000123.
     *
     * Per business and taken from the highest already issued, so it cannot
     * collide with one in use. Lettered on purpose: a company's own codes are
     * barcodes and article numbers, and a glance should say which is which.
     */
    private function nextCode(Business $business): string
    {
        $highest = CompanyProduct::forBusiness($business)
            ->where('code', 'like', 'PRD-%')
            ->orderByRaw('LENGTH(code) DESC')
            ->orderByDesc('code')
            ->value('code');

        $next = $highest === null ? 1 : ((int) substr($highest, 4)) + 1;

        return 'PRD-'.str_pad((string) $next, 6, '0', STR_PAD_LEFT);
    }

    public function store(
        Request $request,
        Business $business,
        RecordDirectDelivery $action,
        ApplyDeliveryPrices $prices,
        UpdateProduct $update,
    ): RedirectResponse|JsonResponse
    {
        $this->authorize('manageOrders', $business);

        $data = $request->validate([
            'company_id' => ['required', 'integer'],
            // Their bill's number is what ties this delivery to their account.
            'invoice_no' => ['required', 'string', 'max:60'],
            'paid' => ['nullable', 'string'],
            'notes' => ['nullable', 'string', 'max:255'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.company_product_id' => ['required', 'integer'],
            // Whole packs, or part of one where packs are opened: 0.5 of a
            // pack of 30 is 15 tablets. Turned into packs and pieces below.
            'lines.*.packs' => ['bail', 'required', 'numeric', 'gt:0', 'max:1000000'],
            'lines.*.rate' => ['nullable', 'string'],
            // What a pharmacy sells at, so a pharmacy cannot leave it blank.
            'lines.*.mrp' => [$business->sellsAtMrp() ? 'required' : 'nullable', 'string'],
            // A month is enough: cartons are printed "09/2027", not to the day.
            'lines.*.expires_on' => ['nullable', 'date_format:Y-m'],
            // The batch printed on the carton, beside its expiry.
            'lines.*.batch_no' => ['nullable', 'string', 'max:60'],
            // The pack as printed on the carton: confirmed, or corrected.
            'lines.*.pack_size' => ['nullable', 'string', 'max:60'],
            // A distributor's own selling price; it quotes per customer and
            // may leave it for later. A pharmacy has none.
            'lines.*.trade' => ['nullable', 'string'],
        ], [
            'lines.*.mrp.required' => 'Line :position needs the MRP printed on the pack — it is the price you sell at.',
            'lines.*.packs.required' => 'Line :position needs a quantity.',
            'lines.*.packs.numeric' => 'Line :position: the quantity must be a number, such as 2 or 0.5.',
            'lines.*.packs.gt' => 'Line :position: the quantity must be more than zero.',
            'lines.*.expires_on.date_format' => 'Line :position: the expiry must be a month and a year.',
            'invoice_no.required' => 'Invoice number is required.',
            'company_id.required' => 'Choose the company first.',
            'lines.required' => 'Add at least one product.',
        ]);

        try {
            /*
             * One piece of work: a delivery that is refused further down
             * leaves no pack size or price changed behind it either.
             */
            $order = DB::transaction(function () use ($business, $data, $request, $update, $prices, $action) {
                // First, so the stock that follows is counted in the right packs.
                $this->applyPackSizes($business, $data['lines'], $request->user(), $update);

                $data['lines'] = $this->packsAndPieces($business, $data['lines']);

                /*
                 * Before the delivery, not after: receiving writes the product's
                 * price history, so every figure has to be on the product by then
                 * or the row records a price that was already out of date.
                 */
                $prices->handle(
                    $business,
                    collect($data['lines'])->keyBy('company_product_id')
                        ->map(fn ($line) => ['mrp' => $line['mrp'] ?? null, 'trade' => $line['trade'] ?? null])
                        ->all(),
                    $request->user(),
                );

                return $action->handle($business, $data, $request->user());
            });
        } catch (LedgerException|\RuntimeException $e) {
            throw ValidationException::withMessages(['lines' => $e->getMessage()]);
        }

        $message = "Delivery recorded as {$order->reference} — {$order->receivedTotal()->format()} into stock.";
        $to = route('businesses.orders.show', [$business, $order]);

        // The delivery screen saves without leaving the page, so what was
        // typed is still there if anything is refused. It is told where to go.
        if ($request->expectsJson()) {
            $request->session()->flash('status', $message);

            return response()->json(['redirect' => $to]);
        }

        return redirect($to)->with('status', $message);
    }

    /**
     * Each line's quantity as whole packs and loose pieces.
     *
     * The quantity is typed in packs. Part of a pack is allowed only where
     * packs are opened and the product says how many pieces one holds; it is
     * then counted in pieces, to the nearest whole one. Every line that
     * cannot be read this way is named, not just the first.
     *
     * @param  list<array<string, mixed>>  $lines
     * @return list<array<string, mixed>>
     */
    private function packsAndPieces(Business $business, array $lines): array
    {
        $products = CompanyProduct::forBusiness($business)
            ->whereIn('id', collect($lines)->pluck('company_product_id')->map(fn ($id) => (int) $id)->all())
            ->get()
            ->keyBy('id');

        $problems = [];

        foreach ($lines as $i => $line) {
            $quantity = (string) $line['packs'];
            $product = $products->get((int) $line['company_product_id']);
            $name = $product?->label() ?? 'Line '.($i + 1);

            if ($product === null) {
                $problems["lines.$i.company_product_id"] = 'Line '.($i + 1).': that product is not in your catalogue any more.';

                continue;
            }

            if (ctype_digit($quantity)) {
                $lines[$i]['packs'] = (int) $quantity;
                $lines[$i]['loose'] = 0;

                continue;
            }

            $per = (int) $product->units_per_pack;

            if (! $business->sellsLoose() || $per < 2) {
                $problems["lines.$i.packs"] = $business->sellsLoose()
                    ? "$name: part of a pack ($quantity) needs a pack size that says how many pieces it holds, such as 3x10. Fill in its pack size, or enter whole packs."
                    : "$name: the quantity must be whole packs.";

                continue;
            }

            $pieces = (int) round((float) $quantity * $per);

            if ($pieces < 1) {
                $problems["lines.$i.packs"] = "$name: $quantity of a pack is less than one piece.";

                continue;
            }

            $lines[$i]['packs'] = intdiv($pieces, $per);
            $lines[$i]['loose'] = $pieces % $per;
        }

        if ($problems !== []) {
            throw ValidationException::withMessages($problems);
        }

        return $lines;
    }
}
