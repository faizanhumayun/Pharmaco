<?php

namespace App\Domain\Migration;

use App\Domain\Companies\Actions\CreateCompany;
use App\Domain\Ledger\ChartOfAccounts;
use App\Domain\Ledger\LedgerPoster;
use App\Domain\Ledger\PostingLine;
use App\Domain\Ledger\PostingSpec;
use App\Domain\Pharmacies\Actions\CreatePharmacy;
use App\Domain\Stock\Actions\RecordStockMovements;
use App\Enums\AccountCode;
use App\Enums\StockMovementType;
use App\Enums\TransactionType;
use App\Models\Business;
use App\Models\Company;
use App\Models\CompanyProduct;
use App\Models\Pharmacy;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * Carries out an {@see OldPosPlan}: the lists first, then the stock, then the
 * balances.
 *
 * Everything goes in through the same doors the application itself uses —
 * companies and customers through their own actions (so each gets its
 * ledger), stock through the one class that writes movements, money through
 * the ledger poster — and the whole of it in one transaction, so a file that
 * fails half way leaves nothing behind.
 *
 * Balances are not new money. The business's opening position already states,
 * or will state, what the market owes and what is owed to companies in total;
 * what the old system adds is who. So each balance is moved from the control
 * account's "Unallocated" onto the customer's or company's own account, which
 * leaves the totals — and the net position — exactly where they were.
 */
class ImportOldPos
{
    public function __construct(
        private readonly CreateCompany $createCompany,
        private readonly CreatePharmacy $createPharmacy,
        private readonly RecordStockMovements $movements,
        private readonly ChartOfAccounts $chart,
        private readonly LedgerPoster $poster,
    ) {}

    /**
     * @param  array<string, mixed>  $plan  from {@see OldPosPlan::build()}, built just now — never one kept from an earlier page
     * @param  array{companies: bool, customers: bool, products: bool, stock: bool, balances: bool}  $take
     * @return array<string, int|string>
     */
    public function handle(Business $business, array $plan, array $take, User $by, string $fileName): array
    {
        return DB::transaction(function () use ($business, $plan, $take, $by, $fileName) {
            $done = [
                'companies' => 0, 'customers' => 0, 'products' => 0,
                'stock_lines' => 0, 'stock_packs' => 0,
                'customer_balances' => 0, 'company_balances' => 0,
                'owed_by_customers' => '0.00', 'owed_to_companies' => '0.00',
            ];

            // Products need their company, so the companies a product names
            // come in with the products even if the supplier list was not asked for.
            $needed = $take['products']
                ? array_flip(array_map(fn ($p) => mb_strtolower($p['company']), array_filter(
                    $plan['products'], fn ($p) => $p['status'] === OldPosPlan::NEW
                )))
                : [];

            foreach ($plan['companies'] as $row) {
                $wanted = $take['companies'] || isset($needed[mb_strtolower($row['name'])]);

                if ($row['status'] === OldPosPlan::NEW && $wanted) {
                    $this->createCompany->handle($business, ['name' => $row['name'], 'phone' => $row['phone']], $by);
                    $done['companies']++;
                }
            }

            if ($take['customers']) {
                foreach ($plan['customers'] as $row) {
                    if ($row['status'] === OldPosPlan::NEW) {
                        $this->createPharmacy->handle($business, [
                            'name' => $row['name'], 'phone' => $row['phone'], 'area' => $row['area'],
                        ], $by);
                        $done['customers']++;
                    }
                }
            }

            if ($take['products']) {
                $companies = Company::forBusiness($business)->get()->keyBy(fn ($c) => mb_strtolower($c->name));

                foreach ($plan['products'] as $row) {
                    if ($row['status'] !== OldPosPlan::NEW) {
                        continue;
                    }

                    $product = CompanyProduct::create([
                        'business_id' => $business->id,
                        'company_id' => $companies[mb_strtolower($row['company'])]->id,
                        'code' => $row['code'],
                        'brand_name' => $row['name'],
                        'dosage_form' => $row['form'],
                        'pack_size' => $row['pack'],
                        'mrp' => $row['mrp'],
                        'trade_price' => $row['trade'],
                        'purchase_rate' => $row['cost'],
                        'reorder_level' => $row['reorder'],
                        'match_key' => $row['match_key'],
                        'is_active' => true,
                        'priced_on' => $business->today()->toDateString(),
                    ]);
                    $done['products']++;

                    if ($take['stock'] && $row['qty'] > 0) {
                        $this->movements->adjust(
                            $business, $product, $row['qty'], StockMovementType::Opening,
                            'Opening stock from the old POS ('.$fileName.')', $by,
                            expiresOn: $row['expires_on'], batchNo: $row['batch_no'],
                        );
                        $done['stock_lines']++;
                        $done['stock_packs'] += $row['qty'];
                    }
                }
            }

            if ($take['balances'] && $business->acceptsTransactions()) {
                [$done['customer_balances'], $done['owed_by_customers']] = $this->allocate(
                    $business, $by, AccountCode::MarketReceivables,
                    Pharmacy::forBusiness($business)->with('account')->get()->keyBy(fn ($c) => mb_strtolower($c->name)),
                    $take['customers'] ? $plan['customers'] : array_filter($plan['customers'], fn ($r) => $r['status'] === OldPosPlan::EXISTS),
                    'Balances brought over from the old POS — what each customer owed',
                    $fileName,
                );

                [$done['company_balances'], $done['owed_to_companies']] = $this->allocate(
                    $business, $by, AccountCode::CompanyPayables,
                    Company::forBusiness($business)->with('account')->get()->keyBy(fn ($c) => mb_strtolower($c->name)),
                    $plan['companies'],
                    'Balances brought over from the old POS — what was owed to each company',
                    $fileName,
                );
            }

            activity()
                ->performedOn($business)
                ->causedBy($by)
                ->withProperties($done + ['file' => $fileName])
                ->event('business.imported')
                ->log('Data imported from an old POS database');

            return $done;
        });
    }

    /**
     * Puts what the old system knew into the business's opening balance draft:
     * what the stock that came in is worth at cost, what customers owed in
     * total, and what was owed to companies in total.
     *
     * Only ever a draft, and only while the opening balance has not been
     * finalised — it is the App Owner who agrees these figures against
     * statements and finalises them. Figures already typed into the draft for
     * anything else (cash, fixed assets, capital) are left as they are.
     *
     * @param  array<string, mixed>  $plan
     * @param  array<string, bool>  $take
     * @return bool whether a draft was written
     */
    public function prefillOpening(Business $business, array $plan, array $take, User $by, string $fileName): bool
    {
        $opening = \App\Models\OpeningBalance::forBusiness($business)->with('lines.account')->first();

        if ($business->opening_date !== null || ($opening && ! $opening->isEditable())) {
            return false;
        }

        // The draft as it stands, so nothing already entered is lost.
        $data = ['opening_date' => $opening?->opening_date?->toDateString() ?? $business->today()->toDateString()];

        foreach ($opening?->lines ?? [] as $line) {
            $data[$line->account->code] = $line->amount->toDecimal();
        }

        $t = $plan['totals'];

        if ($take['stock']) {
            $data[AccountCode::Stock->value] = $t['stock_value'];
        }

        $data[AccountCode::MarketReceivables->value] = $t['owed_by_customers'];
        $data[AccountCode::CompanyPayables->value] = $t['owed_to_companies'];

        $note = 'Stock, market receivables and company payables were filled in from the old POS file '
            .$fileName.' on '.$business->today()->format('j M Y').'. Check each against statements before finalising.';
        $data['notes'] = trim(($opening?->notes ? $opening->notes."\n" : '').$note);

        app(\App\Domain\Opening\Actions\SaveOpeningBalanceDraft::class)->handle($business, $data, $by);

        return true;
    }

    /**
     * The balances a file held, kept for later: who owed what, by name. Used
     * when the business could not take postings at the time of the import, so
     * they can be brought over once its opening balance is finalised without
     * uploading the file again.
     *
     * @param  array<string, mixed>  $plan
     * @return array{customers: list<array{name: string, balance: string}>, companies: list<array{name: string, balance: string}>}
     */
    public function balancesFrom(array $plan): array
    {
        $keep = fn (array $rows) => array_values(array_map(
            fn ($r) => ['name' => $r['name'], 'balance' => $r['balance']],
            array_filter($rows, fn ($r) => Money::of($r['balance'])->isPositive())
        ));

        return ['customers' => $keep($plan['customers']), 'companies' => $keep($plan['companies'])];
    }

    /**
     * Brings kept balances over, once the business can take postings.
     *
     * @param  array{customers: list<array{name: string, balance: string}>, companies: list<array{name: string, balance: string}>}  $kept
     * @return array{customer_balances: int, owed_by_customers: string, company_balances: int, owed_to_companies: string}
     */
    public function bringOverBalances(Business $business, array $kept, User $by, string $fileName): array
    {
        if (! $business->acceptsTransactions()) {
            throw new \App\Exceptions\LedgerException('The opening balance has to be finalised before balances can be brought over.');
        }

        return DB::transaction(function () use ($business, $kept, $by, $fileName) {
            $customers = Pharmacy::forBusiness($business)->with('account')->get()->keyBy(fn ($c) => mb_strtolower($c->name));
            $companies = Company::forBusiness($business)->with('account')->get()->keyBy(fn ($c) => mb_strtolower($c->name));

            // Once only: never onto an account that already has entries.
            $inUse = \App\Models\LedgerEntry::forBusiness($business)->distinct()->pluck('account_id')->flip();
            $ready = fn (array $rows, $parties) => array_map(function ($row) use ($parties, $inUse) {
                $party = $parties[mb_strtolower($row['name'])] ?? null;

                return $row + ['balance_importable' => $party?->account_id !== null && ! isset($inUse[$party->account_id])];
            }, $rows);

            [$c, $owed] = $this->allocate($business, $by, AccountCode::MarketReceivables, $customers, $ready($kept['customers'], $customers),
                'Balances brought over from the old POS — what each customer owed', $fileName);
            [$s, $owing] = $this->allocate($business, $by, AccountCode::CompanyPayables, $companies, $ready($kept['companies'], $companies),
                'Balances brought over from the old POS — what was owed to each company', $fileName);

            return ['customer_balances' => $c, 'owed_by_customers' => $owed, 'company_balances' => $s, 'owed_to_companies' => $owing];
        });
    }

    /**
     * Brings over every balance kept from this business's imports, and marks
     * each import as done. Called when the opening balance is finalised — the
     * moment it first becomes possible — and by the button on an import's
     * own page.
     *
     * @return array{customer_balances: int, company_balances: int}
     */
    public function bringOverKept(Business $business, User $by): array
    {
        $total = ['customer_balances' => 0, 'company_balances' => 0];

        $imports = \App\Models\BusinessImport::where('business_id', $business->id)
            ->where('status', \App\Models\BusinessImport::IMPORTED)
            ->oldest('id')
            ->get()
            ->filter(fn ($import) => is_array($import->result['kept_balances'] ?? null));

        foreach ($imports as $import) {
            $result = $import->result;
            $done = $this->bringOverBalances($business, $result['kept_balances'], $by, $import->original_name);

            unset($result['kept_balances']);
            $import->forceFill(['result' => array_merge($result, $done)])->save();

            $total['customer_balances'] += $done['customer_balances'];
            $total['company_balances'] += $done['company_balances'];
        }

        return $total;
    }

    /**
     * One posting that moves the named balances out of "Unallocated" and onto
     * each party's own account. Nothing is posted if nobody has a balance.
     *
     * @param  \Illuminate\Support\Collection<string, Pharmacy|Company>  $parties
     * @param  iterable<array<string, mixed>>  $rows
     * @return array{0: int, 1: string}
     */
    private function allocate(
        Business $business,
        User $by,
        AccountCode $control,
        $parties,
        iterable $rows,
        string $narration,
        string $fileName,
    ): array {
        $unallocated = $this->chart->openSubLedger($business, $control, $by);
        $onDebit = $control->type()->increasesOnDebit();

        $lines = [];
        $total = Money::zero();
        $count = 0;

        foreach ($rows as $row) {
            $party = $parties[mb_strtolower($row['name'])] ?? null;

            if (! $row['balance_importable'] || $party?->account === null) {
                continue;
            }

            $lines[] = $onDebit
                ? PostingLine::debit($party->account->code, $row['balance'], 'Brought over from the old POS')
                : PostingLine::credit($party->account->code, $row['balance'], 'Brought over from the old POS');

            $total = $total->plus($row['balance']);
            $count++;
        }

        if ($lines === []) {
            return [0, '0.00'];
        }

        $lines[] = $onDebit
            ? PostingLine::credit($unallocated->code, $total)
            : PostingLine::debit($unallocated->code, $total);

        $this->poster->post(new PostingSpec(
            business: $business,
            date: $this->poster->firstOpenDay($business),
            type: TransactionType::Adjustment,
            lines: $lines,
            createdBy: $by,
            narration: $narration,
            reason: 'Imported from '.$fileName.'. Moves balances from Unallocated to named accounts; the total owed is unchanged.',
        ));

        return [$count, $total->toDecimal()];
    }
}
