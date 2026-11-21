<?php

namespace App\Domain\Migration;

use App\Domain\Products\MatchKey;
use App\Models\Business;
use App\Models\Company;
use App\Models\CompanyProduct;
use App\Models\LedgerEntry;
use App\Models\Pharmacy;
use App\Support\Money;
use App\Support\PackSize;

/**
 * What an old POS database would bring into one business — worked out, and
 * nothing written.
 *
 * The preview and the import are the same plan: the preview shows it, the
 * import carries it out, so what was shown is what happens. Each row says
 * whether it is new, already here, or left out and why.
 *
 * Prices follow the kind of business ({@see Business::sellsAtMrp()}): a
 * pharmacy takes the MRP and no trade price; a distributor takes both, and
 * where the old system had no trade price it is left empty rather than
 * invented.
 *
 * Packs follow how the shop kept them ({@see self::pack()}): some wrote the
 * pack size on the product ("10/10") and priced the pack; others priced and
 * counted by the tablet and wrote only how many make a pack. Either way a
 * product comes in as a pack with its pieces, priced by the pack, and stock
 * as whole packs plus whatever is loose.
 */
class OldPosPlan
{
    public const NEW = 'new';
    public const EXISTS = 'exists';
    public const SKIPPED = 'skipped';

    /** Supplier name given to products the old system filed under nobody. */
    public const NO_SUPPLIER = 'Supplier not recorded';

    /**
     * @param  array{products: list<array<string, string>>, suppliers: list<array<string, string>>, customers: list<array<string, string>>}  $data
     * @return array<string, mixed>
     */
    public function build(Business $business, array $data): array
    {
        $companies = $this->companies($business, $data['suppliers'], $data['products']);
        $customers = $this->customers($business, $data['customers']);
        $products = $this->products($business, $data['products']);

        $new = fn (array $rows) => array_values(array_filter($rows, fn ($r) => $r['status'] === self::NEW));
        $sum = fn (array $rows, string $key) => array_reduce(
            $rows, fn (Money $t, $r) => $t->plus($r[$key] ?? '0'), Money::zero()
        );

        $stockRows = array_filter($products, fn ($p) => $p['status'] === self::NEW && ($p['qty'] > 0 || $p['loose'] > 0));

        return [
            'companies' => $companies,
            'customers' => $customers,
            'products' => $products,
            'totals' => [
                'companies_new' => count($new($companies)),
                'companies_exist' => count($companies) - count($new($companies)),
                'customers_new' => count($new($customers)),
                'customers_exist' => count(array_filter($customers, fn ($r) => $r['status'] === self::EXISTS)),
                'customers_skipped' => count(array_filter($customers, fn ($r) => $r['status'] === self::SKIPPED)),
                'products_new' => count($new($products)),
                'products_exist' => count(array_filter($products, fn ($r) => $r['status'] === self::EXISTS)),
                'products_skipped' => count(array_filter($products, fn ($r) => $r['status'] === self::SKIPPED)),
                'stock_lines' => count($stockRows),
                'stock_packs' => array_sum(array_column($stockRows, 'qty')),
                'stock_loose' => array_sum(array_column($stockRows, 'loose')),
                'stock_value' => $sum($stockRows, 'value')->toDecimal(),
                'owed_by_customers' => $sum(array_filter($customers, fn ($r) => $r['balance_importable']), 'balance')->toDecimal(),
                'owed_to_companies' => $sum(array_filter($companies, fn ($r) => $r['balance_importable']), 'balance')->toDecimal(),
                'customer_balances' => count(array_filter($customers, fn ($r) => $r['balance_importable'])),
                'company_balances' => count(array_filter($companies, fn ($r) => $r['balance_importable'])),
            ],
            'balances_possible' => $business->acceptsTransactions(),
        ];
    }

    /**
     * Suppliers, plus any supplier a product names that the supplier list
     * does not have — a product must belong to somebody.
     *
     * @return list<array<string, mixed>>
     */
    private function companies(Business $business, array $suppliers, array $products): array
    {
        $existing = Company::forBusiness($business)->with('account')->get()->keyBy(fn ($c) => $this->key($c->name));
        $used = $this->accountsInUse($business, $existing->pluck('account_id')->filter()->all());

        $rows = [];

        foreach ($suppliers as $s) {
            $name = $this->clean($s['Company'] ?? '') ?: $this->clean($s['Supplier_Name'] ?? '');

            if ($name === '' || isset($rows[$this->key($name)])) {
                continue;
            }

            $rows[$this->key($name)] = [
                'name' => $name,
                'phone' => $this->clean($s['Mob'] ?? '') ?: $this->clean($s['Tel_No'] ?? '') ?: null,
                'area' => $this->clean($s['Area'] ?? '') ?: null,
                'balance' => $this->money($s['Current_Bal'] ?? '0'),
            ];
        }

        // Named on a product but missing from the supplier list.
        foreach ($products as $p) {
            $name = $this->clean($p['Supplier'] ?? '') ?: self::NO_SUPPLIER;

            $rows[$this->key($name)] ??= ['name' => $name, 'phone' => null, 'area' => null, 'balance' => '0.00'];
        }

        return array_values(array_map(function (array $row) use ($existing, $used) {
            $here = $existing[$this->key($row['name'])] ?? null;
            $positive = Money::of($row['balance'])->isPositive();

            return $row + [
                'status' => $here ? self::EXISTS : self::NEW,
                // A balance is brought over once: never onto an account that
                // already has entries of its own.
                'balance_importable' => $positive && ! ($here && in_array($here->account_id, $used, true)),
                'balance_note' => match (true) {
                    ! $positive && ! Money::of($row['balance'])->isZero() => 'In their favour in the old system — not brought over',
                    $positive && $here && in_array($here->account_id, $used, true) => 'Their account here already has entries',
                    default => null,
                },
            ];
        }, $rows));
    }

    /** @return list<array<string, mixed>> */
    private function customers(Business $business, array $customers): array
    {
        $existing = Pharmacy::forBusiness($business)->get()->keyBy(fn ($c) => $this->key($c->name));
        $used = $this->accountsInUse($business, $existing->pluck('account_id')->filter()->all());

        $rows = [];

        foreach ($customers as $c) {
            $name = $this->clean($c['Business'] ?? '') ?: $this->clean($c['Client'] ?? '');

            if ($name === '') {
                continue;
            }

            if (isset($rows[$this->key($name)])) {
                // The same name twice: one account here, with both balances.
                $rows[$this->key($name)]['balance'] = Money::of($rows[$this->key($name)]['balance'])
                    ->plus($this->money($c['Current_Bal'] ?? '0'))->toDecimal();
                $rows[$this->key($name)]['merged'] = true;

                continue;
            }

            $rows[$this->key($name)] = [
                'name' => $name,
                'phone' => $this->clean($c['Contact'] ?? '') ?: $this->clean($c['Whatsapp'] ?? '') ?: null,
                'area' => $this->clean($c['Area'] ?? '') ?: null,
                'balance' => $this->money($c['Current_Bal'] ?? '0'),
                'merged' => false,
            ];
        }

        return array_values(array_map(function (array $row) use ($existing, $used) {
            $here = $existing[$this->key($row['name'])] ?? null;
            $positive = Money::of($row['balance'])->isPositive();

            return $row + [
                'status' => $here ? self::EXISTS : self::NEW,
                'balance_importable' => $positive && ! ($here && in_array($here->account_id, $used, true)),
                'balance_note' => match (true) {
                    ! $positive && ! Money::of($row['balance'])->isZero() => 'Paid ahead in the old system — not brought over',
                    $positive && $here && in_array($here->account_id, $used, true) => 'Their account here already has entries',
                    $row['merged'] => 'Listed more than once in the old system — one account, balances added',
                    default => null,
                },
            ];
        }, $rows));
    }

    /** @return list<array<string, mixed>> */
    private function products(Business $business, array $products): array
    {
        $existing = CompanyProduct::forBusiness($business)->get(['id', 'code', 'match_key']);
        $codes = $existing->pluck('code')->filter()->map(fn ($c) => $this->key($c))->flip();
        $keys = $existing->pluck('match_key')->filter()->flip();

        $seen = [];
        $rows = [];

        foreach ($products as $p) {
            $name = $this->clean($p['Product'] ?? '');
            $code = $this->clean($p['Bcode'] ?? '') ?: null;
            $pack = $this->clean($p['Q_W'] ?? '') ?: null;
            $matchKey = MatchKey::make($code, $name, null, $pack);

            $wholesale = $this->money($p['w_price'] ?? '0');
            $counted = (int) round((float) ($p['Qty'] ?? 0));

            // The pack as it will be kept here: its label, its pieces, its
            // price and cost, and the stock as whole packs and loose pieces.
            $kept = $this->pack($business, $p, $pack, max(0, $counted));
            [$cost, $mrp, $qty] = [$kept['cost'], $kept['mrp'], $counted < 0 ? $counted : $kept['packs']];

            $notes = [];
            $status = self::NEW;

            if ($name === '') {
                $status = self::SKIPPED;
                $notes[] = 'No name';
            } elseif (isset($seen[$matchKey])) {
                $status = self::SKIPPED;
                $notes[] = 'Listed twice in the old system';
            } elseif (isset($keys[$matchKey]) || ($code && isset($codes[$this->key($code)]))) {
                $status = self::EXISTS;
            }

            $seen[$matchKey] = true;

            if ($status === self::NEW) {
                if ($qty < 0) {
                    $notes[] = 'Stock was '.$qty.' in the old system — brought in with none';
                }

                if (! Money::of($mrp)->isPositive()) {
                    $notes[] = 'No MRP';
                }

                if (! Money::of($cost)->isPositive()) {
                    $notes[] = 'No purchase price';
                }

                array_push($notes, ...$kept['notes']);
            }

            $rows[] = [
                'code' => $code,
                'name' => $name,
                'form' => $this->clean($p['Category'] ?? '') ?: null,
                'pack' => $kept['label'],
                'units' => $kept['units'],
                'company' => $this->clean($p['Supplier'] ?? '') ?: self::NO_SUPPLIER,
                'cost' => Money::of($cost)->isPositive() ? $cost : null,
                'mrp' => Money::of($mrp)->isPositive() ? $mrp : null,
                // A pharmacy sells at the MRP and has no trade price; a
                // distributor's is the old wholesale price, or nothing.
                // Where the old system priced by the piece, its "wholesale"
                // figure is the pack's MRP, not a trade price.
                'trade' => ! $business->sellsAtMrp() && ! $kept['by_piece'] && Money::of($wholesale)->isPositive() ? $wholesale : null,
                'qty' => max(0, $qty),
                'loose' => $kept['loose'],
                'value' => $kept['value'],
                'reorder' => max(0, (int) round((float) ($p['Reorder'] ?? 0))),
                'expires_on' => OldPosReader::date($p['Exp_Date'] ?? null)?->toDateString(),
                'batch_no' => $this->clean($p['Batch_No'] ?? '') ?: null,
                'match_key' => $matchKey,
                'status' => $status,
                'notes' => $notes,
            ];
        }

        return $rows;
    }

    /**
     * The pack a product is kept as here, from however the old system held it.
     *
     * The old POS has two habits. Some shops wrote the pack on the product
     * ("10/10") and priced and counted the pack; that is read as it is. Others
     * left that as "Nos" and worked by the piece: a figure for how many pieces
     * make a pack, a price and a cost for one piece, the pack's price beside
     * it, and the stock counted in pieces. That second habit is recognised
     * only when the figures agree with each other — the pack price is the
     * piece price times the pieces, give or take rounding — so a stray number
     * in that column cannot turn one tablet into a box of them.
     *
     * Read that way, the pack takes the pack's price, its cost is the piece
     * cost times the pieces, and the count becomes whole packs with the rest
     * loose. A business that does not open packs keeps the whole packs only.
     *
     * @param  array<string, string>  $p
     * @return array{label: ?string, units: int, by_piece: bool, mrp: string, cost: string, packs: int, loose: int, value: string, notes: list<string>}
     */
    private function pack(Business $business, array $p, ?string $printed, int $counted): array
    {
        $pieceCost = $this->exact($p['S_price'] ?? '0');
        $piecePrice = $this->exact($p['R_price'] ?? '0');
        $packPrice = $this->exact($p['w_price'] ?? '0');

        $ratio = trim((string) ($p['Ol_Wr'] ?? ''));
        $pieces = ctype_digit($ratio) ? (int) $ratio : 1;

        $asItStands = [
            'label' => $printed,
            'units' => PackSize::pieces($printed),
            'by_piece' => false,
            'mrp' => Money::of($piecePrice)->toDecimal(),
            'cost' => Money::of($pieceCost)->toDecimal(),
            'packs' => $counted,
            'loose' => 0,
            'value' => Money::of(bcmul($pieceCost, (string) $counted, 4))->toDecimal(),
            'notes' => [],
        ];

        if ($pieces <= 1) {
            return $asItStands;
        }

        // Do the two prices describe the same pack? Within a twentieth.
        $expected = bcmul($piecePrice, (string) $pieces, 4);
        $gap = bcsub($packPrice, $expected, 4);
        $gap = bccomp($gap, '0', 4) < 0 ? bcmul($gap, '-1', 4) : $gap;
        $agree = bccomp($expected, '0', 4) > 0 && bccomp($gap, bcmul($expected, '0.05', 4), 4) <= 0;

        if (! $agree) {
            return ['notes' => [
                'The old system says '.$pieces.' to a pack, but its prices do not agree with that — brought in as single items. Check this one.',
            ]] + $asItStands;
        }

        $loose = $business->sellsLoose();
        $left = $counted % $pieces;

        return [
            // The pack as the shop wrote it, when it says the same thing.
            'label' => PackSize::pieces($printed) === $pieces ? $printed : $pieces."'s",
            'units' => $pieces,
            'by_piece' => true,
            'mrp' => Money::of($packPrice)->toDecimal(),
            'cost' => Money::of(bcmul($pieceCost, (string) $pieces, 4))->toDecimal(),
            'packs' => intdiv($counted, $pieces),
            'loose' => $loose ? $left : 0,
            'value' => Money::of(bcmul($pieceCost, (string) ($loose ? $counted : $counted - $left), 4))->toDecimal(),
            'notes' => ! $loose && $left > 0
                ? [$left.' loose out of a pack of '.$pieces.' in the old system — not brought in; this business sells whole packs']
                : [],
        ];
    }

    /** A figure as the old system wrote it, to its own four places. */
    private function exact(?string $value): string
    {
        $value = trim((string) $value);

        return is_numeric($value) ? bcadd($value, '0', 4) : '0.0000';
    }

    /**
     * Which of these accounts already carry ledger entries.
     *
     * @param  list<int>  $accountIds
     * @return list<int>
     */
    private function accountsInUse(Business $business, array $accountIds): array
    {
        if ($accountIds === []) {
            return [];
        }

        return LedgerEntry::forBusiness($business)
            ->whereIn('account_id', $accountIds)
            ->distinct()
            ->pluck('account_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    private function clean(?string $value): string
    {
        return trim(preg_replace('/\s+/', ' ', (string) $value) ?? '');
    }

    private function key(string $name): string
    {
        return mb_strtolower($this->clean($name));
    }

    /** The old system writes money to four places; ours has two. */
    private function money(?string $value): string
    {
        $value = trim((string) $value);

        return Money::of(is_numeric($value) ? $value : '0')->toDecimal();
    }
}
