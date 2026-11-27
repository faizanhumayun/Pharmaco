<?php

namespace App\Domain\Migration;

use App\Domain\Businesses\Actions\CreateBusiness;
use App\Domain\Businesses\Actions\SetPaymentMethods;
use App\Domain\Closing\Actions\FinalizeDailyClosing;
use App\Domain\Closing\Actions\ReconcileCash;
use App\Domain\Closing\DailyClosingCalculator;
use App\Domain\Companies\Actions\CreateCompany;
use App\Domain\Daily\DayWriter;
use App\Domain\Opening\Actions\FinalizeOpeningBalance;
use App\Domain\Opening\Actions\SaveOpeningBalanceDraft;
use App\Domain\Orders\Actions\RecordDeliveryPurchase;
use App\Domain\Orders\Actions\RecordDirectDelivery;
use App\Domain\Products\MatchKey;
use App\Enums\AccountCode;
use App\Enums\StockMovementType;
use App\Models\Business;
use App\Models\BusinessPaymentMethod;
use App\Models\Company;
use App\Models\CompanyProduct;
use App\Models\PaymentMethod;
use App\Models\Pharmacy;
use App\Models\PosBill;
use App\Models\StockMovement;
use App\Models\User;
use App\Support\Money;
use Closure;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use ReflectionMethod;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * An old POS database brought in with its history, into a new business.
 *
 * {@see ImportOldPos} brings the lists and the stock as they stand. This goes
 * further, for a shop whose past is wanted too: every day the old system
 * recorded is replayed on its own date — the deliveries that came in, the
 * bills rung up at the counter, refunds, what credit customers paid back and
 * the day's expenses — and each day is then closed, so the books read as if
 * the business had been on Pharmaco from the start.
 *
 * Payments to suppliers are left out on purpose: every delivery comes in
 * unpaid, and the companies' balances are settled here afterwards.
 *
 * It goes through the application's own doors wherever one exists:
 * deliveries through {@see RecordDirectDelivery} (so each has its order,
 * receipt lines, stock movements and price history), the day through the
 * {@see DayWriter}, the opening through the opening balance, and closing
 * through cash reconciliation. Bills are written as the till writes them,
 * without the till's checks — the old system's bills already happened, below
 * cost or not — and a day is written once with all of them rather than once
 * per bill, which would amend it a hundred times.
 *
 * The clock is set to each day while it is replayed, so every record carries
 * the old date, not today's.
 */
class OldPosHistory
{
    /** @var array<string, array{id: int, per: int}> by old barcode */
    private array $products = [];

    /** @var array<string, Company> by lower-case name */
    private array $companies = [];

    /** @var array<int, string> unit cost of one piece, by product id */
    private array $pieceCost = [];

    private ?Closure $say = null;

    public function __construct(
        private readonly OldPosReader $reader,
        private readonly OldPosPlan $planner,
        private readonly ImportOldPos $import,
        private readonly CreateBusiness $createBusiness,
        private readonly CreateCompany $createCompany,
        private readonly SaveOpeningBalanceDraft $saveOpening,
        private readonly FinalizeOpeningBalance $finalizeOpening,
        private readonly SetPaymentMethods $paymentMethods,
        private readonly DayWriter $day,
        private readonly ReconcileCash $reconcile,
        private readonly FinalizeDailyClosing $finalizeClosing,
        private readonly DailyClosingCalculator $calculator,
    ) {}

    /**
     * @return array<string, int|string>
     */
    public function run(string $file, string $name, User $by, bool $close = true, ?string $until = null, ?Closure $say = null): array
    {
        $this->say = $say;
        DB::connection()->disableQueryLog();

        $this->tell('Reading the old POS file');
        $lists = $this->reader->read($file);
        $history = [
            'bills' => $this->table($file, 'Sales_rec'),
            'lines' => $this->table($file, 'Sales_Detail'),
            'deliveries' => $this->table($file, 'Product_rec'),
            'customers' => $this->table($file, 'Customer_acc'),
            'expenses' => $this->table($file, 'Cash_trans'),
        ];

        $days = $this->byDay($history, $until);
        $first = array_key_first($days);
        $last = array_key_last($days);

        if ($first === null) {
            throw new RuntimeException('The file has no bills or deliveries to bring in.');
        }

        $opening = Carbon::parse($first)->subDay()->toDateString();
        $this->freeze($opening, '09:00:00');

        try {
            $business = $this->createBusiness->handle([
                'name' => $name,
                'business_type' => 'pharmacy',
                'notes' => 'Brought in from the old POS ('.basename($file).') with its history, '.$first.' to '.$last.'.',
            ], $by);

            $this->tell("Business {$business->name} ({$business->slug}) — lists");
            $plan = $this->planner->build($business, $lists);
            $this->import->handle($business, $plan, [
                'companies' => true, 'customers' => true, 'products' => false,
                'stock' => false, 'customer_balances' => false, 'company_balances' => false,
            ], $by, basename($file));

            $this->loadCompanies($business);
            $this->createProducts($business, $plan, $lists['products'], $by);
            $this->createMissingProducts($business, $history, $by);

            $this->tell('Opening balance on '.$opening);
            $stock = $this->openingStock($lists['products'], $days);
            $value = $this->open($business, $opening, $stock, $by, basename($file));
            $business->refresh();

            $easypaisa = $this->easypaisa($business, $by);

            $totals = ['days' => 0, 'bills' => 0, 'refunds' => 0, 'deliveries' => 0, 'collections' => 0, 'expenses' => 0, 'skipped_bills' => 0, 'closed' => 0];
            $owed = [];
            $billNo = 0;

            $collector = new CollectedDeliveryPurchase(app(DayWriter::class));
            app()->instance(RecordDeliveryPurchase::class, $collector);
            $deliver = app()->make(RecordDirectDelivery::class);

            for ($date = Carbon::parse($opening)->addDay(); $date->toDateString() <= $last; $date->addDay()) {
                $d = $date->toDateString();
                $work = $days[$d] ?? null;

                DB::transaction(function () use ($business, $by, $d, $work, $deliver, $collector, $easypaisa, $close, &$totals, &$owed, &$billNo) {
                    $this->freeze($d, '09:00:00');
                    $day = Carbon::parse($d, $business->timezone);

                    if ($work !== null) {
                        $sales = [];
                        $returns = [];
                        $collections = [];
                        $expenses = [];
                        $profit = Money::zero();
                        $bills = [];

                        foreach ($work['deliveries'] as $delivery) {
                            if ($this->deliver($business, $deliver, $delivery, $by, $day)) {
                                $totals['deliveries']++;
                            }
                        }

                        $dailyNo = 0;

                        foreach ($work['bills'] as [$bill, $lines]) {
                            $this->freeze($d, $this->time($bill['Time'] ?? ''));
                            $done = $this->bill($business, $bill, $lines, $by, $d, $billNo, $dailyNo, $easypaisa);

                            if ($done === null) {
                                $totals['skipped_bills']++;

                                continue;
                            }

                            if ($done['kind'] === 'refund') {
                                $returns[] = $done['row'];
                                $totals['refunds']++;
                            } else {
                                $sales[] = $done['row'];
                                $bills[] = $done['bill'];
                                $totals['bills']++;

                                if ($done['bill']->customer_name !== null) {
                                    $key = mb_strtolower($done['bill']->customer_name);
                                    $owed[$key] = [
                                        'name' => $done['bill']->customer_name,
                                        'due' => Money::of($owed[$key]['due'] ?? '0')
                                            ->plus($done['bill']->total->minus($done['bill']->received))->toDecimal(),
                                    ];
                                }
                            }

                            $profit = $profit->plus($done['margin']);
                        }

                        $this->freeze($d, '21:00:00');

                        foreach ($work['collections'] as $row) {
                            $key = mb_strtolower($this->clean($row['Client'] ?? '') ?: $this->clean($row['Business'] ?? ''));
                            $due = Money::of($owed[$key]['due'] ?? '0');
                            $paid = Money::of($this->number($row['Debit'] ?? '0'));
                            $amount = $paid->greaterThan($due) ? $due : $paid;

                            if ($amount->isPositive()) {
                                $collections[] = ['pharmacy' => $owed[$key]['name'], 'amount' => $amount->toDecimal(), 'note' => 'Received (old POS)'];
                                $owed[$key]['due'] = $due->minus($amount)->toDecimal();
                                $totals['collections']++;
                            }
                        }

                        foreach ($work['expenses'] as $row) {
                            $amount = Money::of($this->number($row['Credit'] ?? '0'));

                            if ($amount->isPositive()) {
                                $expenses[] = [
                                    'category' => ucwords(mb_strtolower($this->clean($row['Category'] ?? ''))) ?: 'General',
                                    'description' => $this->clean($row['Description'] ?? '') ?: null,
                                    'amount' => $amount->toDecimal(),
                                ];
                                $totals['expenses']++;
                            }
                        }

                        $purchases = $collector->take();

                        if ($sales || $returns || $collections || $expenses || $purchases) {
                            $entry = $this->day->write($business, $day, function (array $data) use ($sales, $returns, $collections, $expenses, $purchases, $profit) {
                                $data['purchases'] = [...($data['purchases'] ?? []), ...$purchases];
                                $data['sales'] = [...($data['sales'] ?? []), ...$sales];
                                $data['returns'] = [...($data['returns'] ?? []), ...$returns];
                                $data['collections'] = [...($data['collections'] ?? []), ...$collections];
                                $data['expenses'] = [...($data['expenses'] ?? []), ...$expenses];
                                $data['gross_profit'] = Money::of($data['gross_profit'] ?? 0)->plus($profit)->toDecimal();

                                return $data;
                            }, $by);

                            $this->link($business, $entry->id, $bills);
                            $totals['days']++;
                        }
                    }

                    if ($close) {
                        $this->freeze($d, '23:30:00');
                        $figures = $this->calculator->compute($business, $day);
                        $this->reconcile->handle($business, $day, $figures['closing_cash'], null, $by);
                        $this->finalizeClosing->handle($business, $day, $by);
                        $totals['closed']++;
                    }
                });

                if ($date->day === 1 || $d === $last) {
                    $this->tell("  {$d}: {$totals['bills']} bills, {$totals['deliveries']} deliveries so far");
                }
            }

            app()->forgetInstance(RecordDeliveryPurchase::class);

            return $totals + [
                'business' => $business->slug,
                'from' => $first,
                'to' => $last,
                'opening_date' => $opening,
                'opening_stock_value' => $value,
                'products' => count(array_unique(array_column($this->products, 'id'))),
            ];
        } finally {
            Carbon::setTestNow();
        }
    }

    // ---------------------------------------------------------------- reading

    /** @return list<array<string, string>> */
    private function table(string $file, string $table): array
    {
        $binary = 'mdb-export';

        foreach (array_filter([config('services.mdbtools.path'), '/opt/homebrew/bin', '/usr/local/bin', '/usr/bin']) as $dir) {
            if (@is_executable($dir.'/mdb-export')) {
                $binary = $dir.'/mdb-export';
                break;
            }
        }

        $process = new Process([$binary, $file, $table]);
        $process->setTimeout(600);
        $process->mustRun();

        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $process->getOutput());
        rewind($stream);

        $header = fgetcsv($stream, null, ',', '"', '');
        $rows = [];

        while ($header && ($line = fgetcsv($stream, null, ',', '"', '')) !== false) {
            if ($line === [null]) {
                continue;
            }

            $rows[] = array_combine($header, array_pad(array_slice($line, 0, count($header)), count($header), ''));
        }

        fclose($stream);

        return $rows;
    }

    /**
     * Everything the file holds, filed under its day.
     *
     * @return array<string, array{deliveries: list<array<string, mixed>>, bills: list<array{0: array<string, string>, 1: list<array<string, string>>}>, collections: list<array<string, string>>, expenses: list<array<string, string>>}>
     */
    private function byDay(array $history, ?string $until): array
    {
        $days = [];
        $blank = ['deliveries' => [], 'bills' => [], 'collections' => [], 'expenses' => []];
        $date = fn (array $row, string $key) => OldPosReader::date($row[$key] ?? null)?->toDateString();
        $keep = fn (?string $d) => $d !== null && ($until === null || $d <= $until);

        // A delivery is one supplier's invoice on one day.
        $groups = [];

        foreach ($history['deliveries'] as $row) {
            $d = $date($row, 'Date');

            if (! $keep($d) || (int) round((float) $row['Qty']) <= 0) {
                continue;
            }

            $supplier = $this->clean($row['Supplier'] ?? '') ?: OldPosPlan::NO_SUPPLIER;
            $invoice = $this->clean($row['Invoice'] ?? '');
            $groups[$d][mb_strtolower($supplier).'|'.$invoice] ??= ['supplier' => $supplier, 'invoice' => $invoice, 'rows' => []];
            $groups[$d][mb_strtolower($supplier).'|'.$invoice]['rows'][] = $row;
        }

        foreach ($groups as $d => $list) {
            $days[$d] ??= $blank;
            $days[$d]['deliveries'] = array_values($list);
        }

        $lines = [];

        foreach ($history['lines'] as $row) {
            $lines[$row['B_No']][] = $row;
        }

        $bills = $history['bills'];
        usort($bills, fn ($a, $b) => [$date($a, 'Sale_Date'), $this->time($a['Time'] ?? ''), $a['B_No']]
            <=> [$date($b, 'Sale_Date'), $this->time($b['Time'] ?? ''), $b['B_No']]);

        foreach ($bills as $bill) {
            $d = $date($bill, 'Sale_Date');

            if ($keep($d) && isset($lines[$bill['B_No']])) {
                $days[$d] ??= $blank;
                $days[$d]['bills'][] = [$bill, $lines[$bill['B_No']]];
            }
        }

        // What a customer paid against their account: a cash entry with no bill.
        foreach ($history['customers'] as $row) {
            $d = $date($row, 'Date');

            if ($keep($d) && trim($row['Cash_Status'] ?? '') === 'Cash' && trim($row['B_No'] ?? '') === '' && (float) $this->number($row['Debit'] ?? '0') > 0) {
                $days[$d] ??= $blank;
                $days[$d]['collections'][] = $row;
            }
        }

        foreach ($history['expenses'] as $row) {
            $d = $date($row, 'Date');

            if ($keep($d) && (float) $this->number($row['Credit'] ?? '0') > 0) {
                $days[$d] ??= $blank;
                $days[$d]['expenses'][] = $row;
            }
        }

        ksort($days);

        // Expenses from before the shop's first bill or delivery belong to no
        // day this business has.
        $start = null;

        foreach ($days as $d => $work) {
            if ($work['bills'] || $work['deliveries']) {
                $start = $d;
                break;
            }
        }

        return array_filter($days, fn ($d) => $start !== null && $d >= $start, ARRAY_FILTER_USE_KEY);
    }

    // --------------------------------------------------------------- products

    private function loadCompanies(Business $business): void
    {
        $this->companies = Company::forBusiness($business)->get()
            ->keyBy(fn ($c) => mb_strtolower($c->name))->all();
    }

    private function company(Business $business, string $name, User $by): Company
    {
        $name = $this->clean($name) ?: OldPosPlan::NO_SUPPLIER;

        return $this->companies[mb_strtolower($name)] ??= $this->createCompany->handle($business, ['name' => $name], $by);
    }

    /**
     * The old product list, as {@see ImportOldPos} would bring it, remembering
     * which old barcode became which product and whether the old system
     * counted it by the piece.
     */
    private function createProducts(Business $business, array $plan, array $raw, User $by): void
    {
        $this->tell('Products');
        $pack = new ReflectionMethod(OldPosPlan::class, 'pack');
        $byKey = [];

        foreach ($plan['products'] as $i => $row) {
            $p = $raw[$i] ?? [];
            $code = $this->key($p['Bcode'] ?? '');

            if ($row['status'] !== OldPosPlan::NEW) {
                if ($code !== '' && isset($byKey[$row['match_key']])) {
                    $this->products[$code] ??= $byKey[$row['match_key']];
                }

                continue;
            }

            $kept = $pack->invoke($this->planner, $business, $p, $this->clean($p['Q_W'] ?? '') ?: null, 0);
            $generic = $this->clean($p['Description'] ?? '');

            $product = CompanyProduct::create([
                'business_id' => $business->id,
                'company_id' => $this->company($business, $row['company'], $by)->id,
                'brand_name' => $row['name'],
                'generic_name' => $generic !== '' && ! is_numeric($generic) ? mb_substr($generic, 0, 200) : null,
                'dosage_form' => $row['form'],
                'pack_size' => $row['pack'],
                'mrp' => $row['mrp'],
                'purchase_rate' => $row['cost'],
                'reorder_level' => $row['reorder'],
                'units_per_pack' => $row['units'],
                'match_key' => $row['match_key'],
                'is_active' => true,
                'priced_on' => $business->today()->toDateString(),
            ]);

            $info = ['id' => $product->id, 'per' => $kept['by_piece'] ? max(1, (int) $row['units']) : 1];
            $byKey[$row['match_key']] = $info;
            $this->pieceCost[$product->id] = $this->exact($p['S_price'] ?? '0');

            if ($code !== '') {
                $this->products[$code] ??= $info;
            }
        }
    }

    /**
     * Products the old system sold or bought but no longer lists: made from
     * what its bills and deliveries say about them, so no line is lost.
     */
    private function createMissingProducts(Business $business, array $history, User $by): void
    {
        $missing = [];

        foreach ([['lines', 'Code', 'Supplier'], ['deliveries', 'Bcode', 'Supplier']] as [$table, $codeField, $supplierField]) {
            foreach ($history[$table] as $row) {
                $code = $this->key($row[$codeField] ?? '');

                if ($code !== '' && ! isset($this->products[$code])) {
                    $missing[$code] ??= $row + ['__code' => $this->clean($row[$codeField]), '__supplier' => $row[$supplierField] ?? ''];
                }
            }
        }

        $this->tell(count($missing).' products no longer on the old list');

        foreach ($missing as $code => $row) {
            $name = $this->clean($row['Product'] ?? '') ?: 'Old POS item '.$row['__code'];
            $company = $this->company($business, $row['__supplier'], $by);
            $key = MatchKey::make($row['__code'], $name);

            $product = CompanyProduct::forBusiness($business)->where('company_id', $company->id)->where('match_key', $key)->first()
                ?? CompanyProduct::create([
                    'business_id' => $business->id,
                    'company_id' => $company->id,
                    'brand_name' => mb_substr($name, 0, 200),
                    'dosage_form' => $this->clean($row['Category'] ?? '') ?: null,
                    'mrp' => Money::of($this->exact($row['R_price'] ?? '0'))->isPositive() ? Money::of($this->exact($row['R_price']))->toDecimal() : null,
                    'purchase_rate' => Money::of($this->exact($row['S_price'] ?? '0'))->isPositive() ? Money::of($this->exact($row['S_price']))->toDecimal() : null,
                    'units_per_pack' => 1,
                    'match_key' => $key,
                    'is_active' => true,
                    'priced_on' => $business->today()->toDateString(),
                ]);

            $this->products[$code] = ['id' => $product->id, 'per' => 1];
            $this->pieceCost[$product->id] ??= $this->exact($row['S_price'] ?? '0');
        }
    }

    // ---------------------------------------------------------------- opening

    /**
     * Pieces of each product on hand the day before the first record.
     *
     * Worked back from the old system's count today through every delivery
     * and sale, and never less than the history needs: stock here cannot go
     * below nothing, so a product sold before any recorded delivery opens
     * with at least what was sold.
     *
     * @return array<int, int> pieces by product id
     */
    private function openingStock(array $raw, array $days): array
    {
        $now = [];

        foreach ($raw as $p) {
            $info = $this->products[$this->key($p['Bcode'] ?? '')] ?? null;

            if ($info !== null) {
                $now[$info['id']] = ($now[$info['id']] ?? 0) + max(0, (int) round((float) ($p['Qty'] ?? 0)));
            }
        }

        $running = [];
        $lowest = [];

        foreach ($days as $work) {
            foreach ($work['deliveries'] as $delivery) {
                foreach ($delivery['rows'] as $row) {
                    $id = $this->products[$this->key($row['Bcode'] ?? '')]['id'] ?? null;

                    if ($id !== null) {
                        $running[$id] = ($running[$id] ?? 0) + (int) round((float) $row['Qty']);
                    }
                }
            }

            foreach ($work['bills'] as [, $lines]) {
                foreach ($lines as $line) {
                    $id = $this->products[$this->key($line['Code'] ?? '')]['id'] ?? null;

                    if ($id !== null) {
                        $running[$id] = ($running[$id] ?? 0) - (int) round((float) $line['Qty']);
                        $lowest[$id] = min($lowest[$id] ?? 0, $running[$id]);
                    }
                }
            }
        }

        $opening = [];

        foreach (array_unique([...array_keys($now), ...array_keys($running)]) as $id) {
            $back = ($now[$id] ?? 0) - ($running[$id] ?? 0);
            $pieces = max(0, $back, -($lowest[$id] ?? 0));

            if ($pieces > 0) {
                $opening[$id] = $pieces;
            }
        }

        return $opening;
    }

    /** @param array<int, int> $stock */
    private function open(Business $business, string $date, array $stock, User $by, string $fileName): string
    {
        $per = [];

        foreach ($this->products as $info) {
            $per[$info['id']] = $info['per'];
        }

        $products = CompanyProduct::forBusiness($business)->whereIn('id', array_keys($stock))->get()->keyBy('id');
        $value = Money::zero();
        $moves = [];

        foreach ($stock as $id => $pieces) {
            $product = $products[$id];
            $size = $per[$id] ?? 1;
            $packs = intdiv($pieces, $size);
            $loose = $pieces % $size;
            $cost = $product->purchase_rate ?? Money::of(bcmul($this->pieceCost[$id] ?? '0', (string) $size, 4));

            $value = $value->plus(bcmul($this->pieceCost[$id] ?? '0', (string) $pieces, 4));
            $moves[] = [$product, $packs, $loose, $cost];
        }

        $opening = $this->saveOpening->handle($business, [
            'opening_date' => $date,
            AccountCode::Stock->value => $value->toDecimal(),
            AccountCode::Cash->value => '0.00',
            AccountCode::MarketReceivables->value => '0.00',
            AccountCode::CompanyPayables->value => '0.00',
            AccountCode::OwnerCapital->value => $value->toDecimal(),
            'notes' => 'Opening stock worked out from the old POS file '.$fileName.': its stock count, taken back through every delivery and sale it recorded. '
                .'The owner\'s capital is that stock. Supplier payments were not brought over, so every delivery since is still owed.',
        ], $by);

        $this->finalizeOpening->handle($opening, $by, 'Opening position brought in from the old POS');

        foreach ($moves as [$product, $packs, $loose, $cost]) {
            StockMovement::create([
                'business_id' => $business->id,
                'company_product_id' => $product->id,
                'business_date' => $date,
                'type' => StockMovementType::Opening,
                'packs' => $packs,
                'loose' => $loose,
                'unit_cost' => Money::of($cost)->toDecimal(),
                'note' => 'Opening stock from the old POS ('.$fileName.')',
                'created_by' => $by->id,
            ]);
        }

        return $value->toDecimal();
    }

    private function easypaisa(Business $business, User $by): ?int
    {
        $method = PaymentMethod::where('name', 'Easypaisa')->first();

        if ($method === null) {
            return null;
        }

        $this->paymentMethods->handle($business, [$method->id], [], $by);

        return BusinessPaymentMethod::forBusiness($business)->where('payment_method_id', $method->id)->value('id');
    }

    // ------------------------------------------------------------------- days

    private function deliver(Business $business, RecordDirectDelivery $deliver, array $delivery, User $by, Carbon $day): bool
    {
        $lines = [];

        foreach ($delivery['rows'] as $row) {
            $info = $this->products[$this->key($row['Bcode'] ?? '')] ?? null;
            $pieces = (int) round((float) $row['Qty']);

            if ($info === null || $pieces <= 0) {
                continue;
            }

            $expiry = OldPosReader::date($row['Exp_Date'] ?? null);

            $lines[] = [
                'company_product_id' => $info['id'],
                'packs' => (string) intdiv($pieces, $info['per']),
                'loose' => (string) ($pieces % $info['per']),
                'rate' => Money::of(bcmul($this->exact($row['S_price'] ?? '0'), (string) $info['per'], 4))->toDecimal(),
                'expires_on' => $expiry !== null && $expiry->toDateString() > $day->toDateString() ? $expiry->toDateString() : null,
                'batch_no' => $this->clean($row['Batch_No'] ?? '') ?: null,
            ];
        }

        if ($lines === []) {
            return false;
        }

        $deliver->handle($business, [
            'company_id' => $this->company($business, $delivery['supplier'], $by)->id,
            'invoice_no' => $delivery['invoice'] !== '' ? mb_substr($delivery['invoice'], 0, 60) : null,
            'paid' => '0',
            'notes' => 'From the old POS'.($delivery['invoice'] !== '' ? ', invoice '.$delivery['invoice'] : ''),
            'lines' => $lines,
        ], $by, $day);

        return true;
    }

    /**
     * One old bill, written the way the till writes one.
     *
     * The old system stored a bill's total two ways — sometimes before the
     * discount, sometimes after — so the lines decide: where they add up to
     * the stored total, the discount still has to come off it.
     *
     * @return array{kind: string, row: array<string, mixed>, margin: Money, bill?: PosBill}|null
     */
    private function bill(Business $business, array $old, array $oldLines, User $by, string $date, int &$billNo, int &$dailyNo, ?int $easypaisa): ?array
    {
        $mode = trim($old['Payment_Mode'] ?? '');
        $refund = $mode === 'Refund' || (float) $old['sTotal'] < 0;

        $items = [];

        foreach ($oldLines as $line) {
            $info = $this->products[$this->key($line['Code'] ?? '')] ?? null;
            $pieces = (int) round((float) $line['Qty']);

            if ($info === null || $pieces === 0 || ($refund ? $pieces > 0 : $pieces < 0)) {
                continue;
            }

            $pieces = abs($pieces);
            $item = &$items[$info['id']];
            $item ??= ['per' => $info['per'], 'pieces' => 0, 'total' => '0', 'cost' => '0',
                'price' => $this->exact($line['R_price'] ?? '0'), 'name' => $this->clean($line['Product'] ?? '')];
            $item['pieces'] += $pieces;
            $item['total'] = bcadd($item['total'], ltrim($this->exact($line['sTotal'] ?? '0'), '-'), 4);
            $item['cost'] = bcadd($item['cost'], bcmul($this->lineCost($line, $info), (string) $pieces, 4), 4);
            unset($item);
        }

        if ($items === []) {
            return null;
        }

        $gross = Money::zero();
        $cost = Money::zero();

        foreach ($items as $item) {
            $gross = $gross->plus($item['total']);
            $cost = $cost->plus($item['cost']);
        }

        $stored = Money::of($this->number($old['sTotal'] ?? '0'))->absolute();
        $discount = Money::of($this->number($old['Discount'] ?? '0'))->absolute();
        $net = $gross->minus($stored)->absolute()->lessThan('1') ? $stored->minus($discount) : $stored;

        if ($net->isNegative() || $net->greaterThan($gross)) {
            $net = $gross;
        }

        $products = CompanyProduct::forBusiness($business)->whereIn('id', array_keys($items))->get()->keyBy('id');
        $reference = $this->clean($old['B_No'] ?? '');
        $cashier = $this->clean($old['User'] ?? '');

        if ($refund) {
            foreach ($items as $id => $item) {
                StockMovement::create([
                    'business_id' => $business->id,
                    'company_product_id' => $id,
                    'business_date' => $date,
                    'type' => StockMovementType::SaleReturn,
                    'packs' => intdiv($item['pieces'], $item['per']),
                    'loose' => $item['pieces'] % $item['per'],
                    'unit_cost' => Money::of(bcmul(bcdiv($item['cost'], (string) $item['pieces'], 6), (string) $item['per'], 4))->toDecimal(),
                    'note' => 'Refund on the old POS, '.$reference,
                    'created_by' => $by->id,
                ]);
            }

            return [
                'kind' => 'refund',
                'row' => ['pharmacy' => '', 'reference' => 'Old POS refund '.$reference, 'amount' => $net->toDecimal(), 'refunded' => $net->toDecimal()],
                'margin' => $cost->minus($net),
            ];
        }

        $credit = $mode === 'Credit';
        $customer = $credit ? ($this->clean($old['Client_Name'] ?? '') ?: 'Credit customer') : null;
        $received = $net;

        if ($credit) {
            $received = Money::of($this->number($old['Paid'] ?? '0'))->minus($this->number($old['Change'] ?? '0'));
            $received = $received->isNegative() ? Money::zero() : ($received->greaterThan($net) ? $net : $received);
        }

        $bill = PosBill::create([
            'business_id' => $business->id,
            'business_date' => $date,
            'bill_no' => ++$billNo,
            'daily_no' => ++$dailyNo,
            'customer_name' => $customer,
            'total' => $net->toDecimal(),
            'discount' => $gross->minus($net)->toDecimal(),
            'received' => $received->toDecimal(),
            'business_payment_method_id' => $mode === 'Easypaisa' && $received->isPositive() ? $easypaisa : null,
            'cost' => $cost->toDecimal(),
            'note' => 'Old POS bill '.$reference.($cashier !== '' ? ' · '.$cashier : ''),
            'created_by' => $by->id,
        ]);

        foreach ($items as $id => $item) {
            $product = $products[$id];
            $size = $item['per'];
            $unitCost = Money::of(bcmul(bcdiv($item['cost'], (string) $item['pieces'], 6), (string) $size, 4));

            $line = $bill->lines()->create([
                'business_id' => $business->id,
                'company_product_id' => $id,
                'name' => $product->label(),
                'quantity' => intdiv($item['pieces'], $size),
                'loose_quantity' => $item['pieces'] % $size,
                'units_per_pack' => max(1, (int) ($product->units_per_pack ?: $size)),
                'unit_price' => Money::of(bcmul($item['price'], (string) $size, 4))->toDecimal(),
                'unit_cost' => $unitCost->toDecimal(),
                'line_total' => Money::of($item['total'])->toDecimal(),
            ]);

            StockMovement::create([
                'business_id' => $business->id,
                'company_product_id' => $id,
                'business_date' => $date,
                'type' => StockMovementType::Sale,
                'packs' => -$line->quantity,
                'loose' => -$line->loose_quantity,
                'unit_cost' => $unitCost->toDecimal(),
                'source_type' => $bill->getMorphClass(),
                'source_id' => $bill->id,
                'note' => 'Sold on bill '.$bill->reference(),
                'created_by' => $by->id,
            ]);
        }

        return [
            'kind' => 'sale',
            'bill' => $bill,
            'row' => [
                'pharmacy' => $customer ?? '',
                'invoice_no' => $bill->reference(),
                'amount' => $bill->total->toDecimal(),
                'received' => $bill->received->toDecimal(),
                'via' => $bill->business_payment_method_id,
            ],
            'margin' => $bill->margin(),
        ];
    }

    /** @param list<PosBill> $bills */
    private function link(Business $business, int $entryId, array $bills): void
    {
        if ($bills === []) {
            return;
        }

        PosBill::whereKey(array_map(fn ($b) => $b->id, $bills))->update(['daily_entry_id' => $entryId]);

        foreach ($bills as $bill) {
            if ($bill->customer_name !== null) {
                $bill->forceFill([
                    'pharmacy_id' => Pharmacy::forBusiness($business)
                        ->whereRaw('LOWER(name) = ?', [mb_strtolower($bill->customer_name)])->value('id'),
                ])->saveQuietly();
            }
        }
    }

    /**
     * What one piece on an old bill line cost.
     *
     * The old system's own figure, except where it is plainly the cost of the
     * whole pack written against one tablet (it did this in its first weeks):
     * more than the piece sold for. That is divided back down by the pack, and
     * failing that the product's own piece cost is used, and failing that the
     * price it sold at — a line with no margin rather than an invented loss.
     *
     * @param  array<string, string>  $line
     * @param  array{id: int, per: int}  $info
     */
    private function lineCost(array $line, array $info): string
    {
        $cost = $this->exact($line['S_price'] ?? '0');
        $price = $this->exact($line['R_price'] ?? '0');

        if (bccomp($price, '0', 4) <= 0 || bccomp($cost, $price, 4) <= 0) {
            return $cost;
        }

        foreach (array_filter([$info['per'] > 1 ? bcdiv($cost, (string) $info['per'], 4) : null, $this->pieceCost[$info['id']] ?? null]) as $guess) {
            if (bccomp($guess, '0', 4) > 0 && bccomp($guess, $price, 4) <= 0) {
                return $guess;
            }
        }

        return $price;
    }

    // ---------------------------------------------------------------- helpers

    private function freeze(string $date, string $time): void
    {
        Carbon::setTestNow(Carbon::parse($date.' '.$time, 'Asia/Karachi'));
    }

    /** The time of day an old bill was rung up: "12/30/99 22:03:00" is 22:03. */
    private function time(string $value): string
    {
        return preg_match('/(\d{2}:\d{2}:\d{2})\s*$/', $value, $m) ? $m[1] : '12:00:00';
    }

    private function clean(?string $value): string
    {
        return trim(preg_replace('/\s+/', ' ', (string) $value) ?? '');
    }

    private function key(?string $value): string
    {
        return mb_strtolower($this->clean($value));
    }

    private function number(?string $value): string
    {
        $value = trim((string) $value);

        return is_numeric($value) ? $value : '0';
    }

    private function exact(?string $value): string
    {
        return bcadd($this->number($value), '0', 4);
    }

    private function tell(string $line): void
    {
        if ($this->say) {
            ($this->say)($line);
        }
    }
}
