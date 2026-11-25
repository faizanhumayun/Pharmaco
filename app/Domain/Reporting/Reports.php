<?php

namespace App\Domain\Reporting;

use App\Domain\Market\Outstanding;
use App\Domain\Stock\StockLedger;
use App\Enums\OrderStatus;
use App\Models\Business;
use App\Models\CollectionLine;
use App\Models\CompanyProduct;
use App\Models\Order;
use App\Models\PosBill;
use App\Models\PosBillLine;
use App\Models\PosReturn;
use App\Models\PosReturnLine;
use App\Models\SaleLine;
use App\Support\Money;
use Illuminate\Support\Carbon;

/**
 * The operational reports: what was sold, bought, returned and is held.
 *
 * Each report is a table — columns, rows, a totals row — so the screen and the
 * spreadsheet export are the same figures drawn twice. They read the documents
 * (bills, deliveries, returns, stock movements); the books stay the business of
 * BalanceService and the ledger screens.
 *
 * A column marked `position` shows cost or profit and is left out for anyone
 * who may not see the business's position.
 */
class Reports
{
    public function __construct(
        private readonly StockLedger $stock,
        private readonly Outstanding $outstanding,
    ) {}

    /** @return array<string, array{title: string, group: string, about: string, dated: bool}> */
    public static function catalogue(): array
    {
        return [
            'sales' => ['title' => 'Sales by bill', 'group' => 'Sales', 'dated' => true,
                'about' => 'Every bill in the period: the customer, the bill total, how it was paid and the pending amount.'],
            'sales-by-product' => ['title' => 'Sales by product', 'group' => 'Sales', 'dated' => true,
                'about' => 'Products sold and their quantities, highest seller first. Values are before any whole-bill discount.'],
            'sales-by-customer' => ['title' => 'Sales by customer', 'group' => 'Sales', 'dated' => true,
                'about' => 'Each customer\'s bills: the amount paid at the time of sale, the amount returned and the pending amount.'],
            'returns' => ['title' => 'Returns', 'group' => 'Sales', 'dated' => true,
                'about' => 'Returned items: against which bill, and whether the amount was refunded or deducted from the account.'],
            'payments' => ['title' => 'Money received by method', 'group' => 'Sales', 'dated' => true,
                'about' => 'Money received in cash and through each wallet and bank, from sales and collections in the period.'],
            'purchases' => ['title' => 'Purchases by delivery', 'group' => 'Purchases', 'dated' => true,
                'about' => 'Every delivery received: from which company, on which invoice, and what it cost.'],
            'purchases-by-product' => ['title' => 'Purchases by product', 'group' => 'Purchases', 'dated' => true,
                'about' => 'Products bought and their quantities, by value.'],
            'stock' => ['title' => 'Stock on hand', 'group' => 'Stock', 'dated' => false,
                'about' => 'All stock held now, with its value at cost.'],
            'reorder' => ['title' => 'Reorder list', 'group' => 'Stock', 'dated' => false,
                'about' => 'Products at or below their reorder level. A product with no reorder level is not shown.'],
        ];
    }

    /** @return array{columns: array<int, array<string, mixed>>, rows: array<int, array<string, mixed>>, totals: array<string, mixed>} */
    public function build(string $report, Business $business, Carbon $from, Carbon $to, bool $position): array
    {
        $table = match ($report) {
            'sales' => $this->sales($business, $from, $to),
            'sales-by-product' => $this->salesByProduct($business, $from, $to),
            'sales-by-customer' => $this->salesByCustomer($business, $from, $to),
            'returns' => $this->returns($business, $from, $to),
            'payments' => $this->payments($business, $from, $to),
            'purchases' => $this->purchases($business, $from, $to),
            'purchases-by-product' => $this->purchasesByProduct($business, $from, $to),
            'stock' => $this->stockOnHand($business, false),
            'reorder' => $this->stockOnHand($business, true),
        };

        if (! $position) {
            $table['columns'] = array_values(array_filter($table['columns'], fn ($c) => ! ($c['position'] ?? false)));
        }

        return $table;
    }

    private function col(string $key, string $label, string $type = 'text', bool $position = false): array
    {
        return ['key' => $key, 'label' => $label, 'type' => $type, 'position' => $position];
    }

    /** @param array<int, string> $moneyKeys */
    private function totals(array $rows, array $moneyKeys, array $numberKeys = []): array
    {
        $totals = [];

        foreach ($moneyKeys as $key) {
            $totals[$key] = Money::sum(array_column($rows, $key));
        }

        foreach ($numberKeys as $key) {
            $totals[$key] = array_sum(array_column($rows, $key));
        }

        return $totals;
    }

    private function bills(Business $business, Carbon $from, Carbon $to)
    {
        return PosBill::forBusiness($business)
            ->whereBetween('business_date', [$from->toDateString(), $to->toDateString()]);
    }

    private function sales(Business $business, Carbon $from, Carbon $to): array
    {
        $collected = $this->outstanding->collectedByBill($business);

        $rows = $this->bills($business, $from, $to)
            ->with(['paymentMethod.method', 'returns', 'creator'])
            ->withCount('lines')
            ->orderByDesc('business_date')->orderByDesc('id')
            ->get()
            ->map(function (PosBill $bill) use ($collected) {
                $bill->setAttribute('collected', $collected[$bill->id] ?? Money::zero());

                return [
                    'date' => $bill->business_date->format('d M Y'),
                    'bill' => $bill->reference(),
                    'customer' => $bill->customer_name ?? 'Walk-in customer',
                    'items' => $bill->lines_count,
                    'discount' => $bill->discount,
                    'total' => $bill->total,
                    'received' => $bill->received->greaterThan($bill->total) ? $bill->total : $bill->received,
                    'paid_by' => $bill->received->isPositive() ? ($bill->paymentMethod?->label() ?? 'Cash') : '—',
                    'returned' => $bill->returned(),
                    'owing' => $this->outstanding->onBill($bill),
                    'cost' => $bill->cost,
                    'margin' => $bill->margin(),
                    'by' => $bill->creator?->name,
                ];
            })->all();

        return [
            'columns' => [
                $this->col('date', 'Date'), $this->col('bill', 'Bill'), $this->col('customer', 'Customer'),
                $this->col('items', 'Items', 'number'), $this->col('discount', 'Discount', 'money'),
                $this->col('total', 'Total', 'money'), $this->col('received', 'Received', 'money'),
                $this->col('paid_by', 'Paid by'), $this->col('returned', 'Returned', 'money'),
                $this->col('owing', 'Pending', 'money'),
                $this->col('cost', 'Cost', 'money', true), $this->col('margin', 'Profit', 'money', true),
                $this->col('by', 'Sold by'),
            ],
            'rows' => $rows,
            'totals' => $this->totals($rows, ['discount', 'total', 'received', 'returned', 'owing', 'cost', 'margin'], ['items']),
        ];
    }

    private function salesByProduct(Business $business, Carbon $from, Carbon $to): array
    {
        $inPeriod = fn ($q) => $q->whereBetween('business_date', [$from->toDateString(), $to->toDateString()]);

        $back = PosReturnLine::forBusiness($business)
            ->whereHas('return', $inPeriod)
            ->get()
            ->groupBy('company_product_id');

        $rows = PosBillLine::forBusiness($business)
            ->whereHas('bill', $inPeriod)
            ->with('product.company')
            ->get()
            ->groupBy(fn (PosBillLine $l) => $l->company_product_id ?? 'x'.$l->name)
            ->map(function ($lines) use ($back) {
                /** @var PosBillLine $first */
                $first = $lines->first();
                $returned = $back->get($first->company_product_id, collect());
                $sales = Money::sum($lines->pluck('line_total'));
                $cost = Money::sum($lines->map(fn (PosBillLine $l) => $l->cost()));

                return [
                    'product' => $first->product?->label() ?? $first->name,
                    'company' => $first->product?->company?->name ?? '—',
                    'bills' => $lines->pluck('pos_bill_id')->unique()->count(),
                    'quantity' => $lines->sum('quantity'),
                    'loose' => $lines->sum('loose_quantity'),
                    'returned_qty' => $returned->sum('quantity'),
                    'sales' => $sales,
                    'returned' => Money::sum($returned->pluck('line_total')),
                    'cost' => $cost,
                    'margin' => $sales->minus($cost),
                ];
            })
            ->sortByDesc(fn ($row) => (float) $row['sales']->toDecimal())
            ->values()->all();

        return [
            'columns' => [
                $this->col('product', 'Product'), $this->col('company', 'Company'),
                $this->col('bills', 'Bills', 'number'), $this->col('quantity', ucfirst($business->unit()->many()).' sold', 'number'),
                $this->col('loose', 'Loose pieces', 'number'), $this->col('returned_qty', 'Returned', 'number'),
                $this->col('sales', 'Sales', 'money'), $this->col('returned', 'Returned value', 'money'),
                $this->col('cost', 'Cost', 'money', true), $this->col('margin', 'Profit', 'money', true),
            ],
            'rows' => $rows,
            'totals' => $this->totals($rows, ['sales', 'returned', 'cost', 'margin'], ['quantity', 'loose', 'returned_qty']),
        ];
    }

    private function salesByCustomer(Business $business, Carbon $from, Carbon $to): array
    {
        $collected = $this->outstanding->collectedByBill($business);

        $rows = $this->bills($business, $from, $to)
            ->with('returns')
            ->get()
            ->groupBy(fn (PosBill $b) => $b->customer_name ?? '')
            ->map(function ($bills, $name) use ($collected) {
                $bills->each(fn (PosBill $b) => $b->setAttribute('collected', $collected[$b->id] ?? Money::zero()));
                $sales = Money::sum($bills->pluck('total'));
                $cost = Money::sum($bills->pluck('cost'));

                return [
                    'customer' => $name === '' ? 'Walk-in customers (no name)' : $name,
                    'bills' => $bills->count(),
                    'sales' => $sales,
                    'received' => Money::sum($bills->map(fn (PosBill $b) => $b->received->greaterThan($b->total) ? $b->total : $b->received)),
                    'collected' => Money::sum($bills->map(fn (PosBill $b) => $b->getAttribute('collected'))),
                    'returned' => Money::sum($bills->map(fn (PosBill $b) => $b->returned())),
                    'owing' => Money::sum($bills->map(fn (PosBill $b) => $this->outstanding->onBill($b))),
                    'margin' => $sales->minus($cost),
                ];
            })
            ->sortByDesc(fn ($row) => (float) $row['sales']->toDecimal())
            ->values()->all();

        return [
            'columns' => [
                $this->col('customer', 'Customer'), $this->col('bills', 'Bills', 'number'),
                $this->col('sales', 'Sales', 'money'), $this->col('received', 'Paid at the time', 'money'),
                $this->col('collected', 'Collected later', 'money'), $this->col('returned', 'Returned', 'money'),
                $this->col('owing', 'Pending', 'money'), $this->col('margin', 'Profit', 'money', true),
            ],
            'rows' => $rows,
            'totals' => $this->totals($rows, ['sales', 'received', 'collected', 'returned', 'owing', 'margin'], ['bills']),
        ];
    }

    private function returns(Business $business, Carbon $from, Carbon $to): array
    {
        $rows = PosReturn::forBusiness($business)
            ->whereBetween('business_date', [$from->toDateString(), $to->toDateString()])
            ->with(['bill', 'lines', 'creator'])
            ->orderByDesc('business_date')->orderByDesc('id')
            ->get()
            ->map(fn (PosReturn $r) => [
                'date' => $r->business_date->format('d M Y'),
                'return' => $r->reference(),
                'bill' => $r->bill?->reference(),
                'customer' => $r->bill?->customer_name ?? 'Walk-in customer',
                'items' => $r->lines->map(fn ($l) => $l->name.' × '.$l->quantityLabel())->implode('; '),
                'total' => $r->total,
                'refunded' => $r->refunded,
                'on_account' => $r->onAccount(),
                'reason' => $r->reason ?? '',
                'by' => $r->creator?->name,
            ])->all();

        return [
            'columns' => [
                $this->col('date', 'Date'), $this->col('return', 'Return'), $this->col('bill', 'Bill'),
                $this->col('customer', 'Customer'), $this->col('items', 'Items returned'),
                $this->col('total', 'Value', 'money'), $this->col('refunded', 'Refunded in cash', 'money'),
                $this->col('on_account', 'Deducted from account', 'money'), $this->col('reason', 'Reason'), $this->col('by', 'Recorded by'),
            ],
            'rows' => $rows,
            'totals' => $this->totals($rows, ['total', 'refunded', 'on_account']),
        ];
    }

    private function payments(Business $business, Carbon $from, Carbon $to): array
    {
        $onDays = fn ($q) => $q->where('business_id', $business->id)
            ->whereBetween('business_date', [$from->toDateString(), $to->toDateString()]);

        $sales = SaleLine::query()->whereHas('dailyEntry', $onDays)->with('paymentMethod.method')->get()
            ->groupBy(fn (SaleLine $l) => $l->paymentMethod?->label() ?? 'Cash')
            ->map(fn ($lines) => Money::sum($lines->pluck('received')));

        $collections = CollectionLine::query()->whereHas('dailyEntry', $onDays)->with('paymentMethod.method')->get()
            ->groupBy(fn (CollectionLine $l) => $l->paymentMethod?->label() ?? 'Cash')
            ->map(fn ($lines) => Money::sum($lines->pluck('amount')));

        $rows = $sales->keys()->merge($collections->keys())->unique()
            ->sortBy(fn ($name) => $name === 'Cash' ? '' : $name)
            ->map(function (string $name) use ($sales, $collections) {
                $fromSales = $sales[$name] ?? Money::zero();
                $fromCollections = $collections[$name] ?? Money::zero();

                return [
                    'method' => $name,
                    'sales' => $fromSales,
                    'collections' => $fromCollections,
                    'total' => $fromSales->plus($fromCollections),
                ];
            })->values()->all();

        return [
            'columns' => [
                $this->col('method', 'Paid by'), $this->col('sales', 'On sales', 'money'),
                $this->col('collections', 'On collections', 'money'), $this->col('total', 'Total received', 'money'),
            ],
            'rows' => $rows,
            'totals' => $this->totals($rows, ['sales', 'collections', 'total']),
        ];
    }

    private function deliveries(Business $business, Carbon $from, Carbon $to)
    {
        return Order::forBusiness($business)
            ->where('status', OrderStatus::Received)
            ->whereBetween('received_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->with(['company', 'receiptLines.product', 'purchaseLine'])
            ->orderByDesc('received_at')
            ->get();
    }

    private function purchases(Business $business, Carbon $from, Carbon $to): array
    {
        $rows = $this->deliveries($business, $from, $to)->map(function (Order $order) use ($business) {
            $value = $order->receivedTotal();
            $paid = $order->purchaseLine?->paid ?? Money::zero();

            return [
                'date' => $order->received_at->timezone($business->timezone)->format('d M Y'),
                'reference' => $order->reference,
                'company' => $order->company?->name,
                'invoice' => $order->purchaseLine?->invoice_no ?? '',
                'lines' => $order->receiptLines->where('packs', '>', 0)->count(),
                'packs' => (int) $order->receiptLines->sum('packs'),
                'value' => $value,
                'paid' => $paid,
                'on_account' => $value->minus($paid)->isNegative() ? Money::zero() : $value->minus($paid),
            ];
        })->all();

        return [
            'columns' => [
                $this->col('date', 'Received'), $this->col('reference', 'Delivery'), $this->col('company', 'Company'),
                $this->col('invoice', 'Invoice no.'), $this->col('lines', 'Lines', 'number'),
                $this->col('packs', ucfirst($business->unit()->many()), 'number'), $this->col('value', 'Value', 'money'),
                $this->col('paid', 'Paid at the time', 'money'), $this->col('on_account', 'Pending', 'money'),
            ],
            'rows' => $rows,
            'totals' => $this->totals($rows, ['value', 'paid', 'on_account'], ['lines', 'packs']),
        ];
    }

    private function purchasesByProduct(Business $business, Carbon $from, Carbon $to): array
    {
        $rows = $this->deliveries($business, $from, $to)
            ->flatMap(fn (Order $order) => $order->receiptLines->where('packs', '>', 0)->map(fn ($line) => [
                'key' => $line->company_product_id ?? 'x'.$line->brand_name,
                'product' => $line->product?->label() ?? $line->label(),
                'company' => $order->company?->name,
                'packs' => (int) $line->packs,
                'value' => $line->amount($order->discount_percent),
                'rate' => $line->rate,
                'batch' => $line->batch_no,
            ]))
            ->groupBy('key')
            ->map(fn ($lines) => [
                'product' => $lines->first()['product'],
                'company' => $lines->first()['company'],
                'deliveries' => $lines->count(),
                'packs' => $lines->sum('packs'),
                'value' => Money::sum($lines->pluck('value')),
                'last_rate' => $lines->first()['rate'], // deliveries arrive newest first
                'batches' => $lines->pluck('batch')->filter()->unique()->implode(', '),
            ])
            ->sortByDesc(fn ($row) => (float) $row['value']->toDecimal())
            ->values()->all();

        return [
            'columns' => [
                $this->col('product', 'Product'), $this->col('company', 'Company'),
                $this->col('deliveries', 'Deliveries', 'number'), $this->col('packs', ucfirst($business->unit()->many()), 'number'),
                $this->col('value', 'Value', 'money'), $this->col('last_rate', 'Latest rate', 'money'),
                $this->col('batches', 'Batches'),
            ],
            'rows' => $rows,
            'totals' => $this->totals($rows, ['value'], ['packs']),
        ];
    }

    private function stockOnHand(Business $business, bool $reorderOnly): array
    {
        $onHand = $this->stock->onHandByProduct($business);
        $loose = $this->stock->looseByProduct($business);

        $rows = CompanyProduct::forBusiness($business)
            ->where('is_active', true)
            ->when($reorderOnly, fn ($q) => $q->where('reorder_level', '>', 0))
            ->with('company:id,name')
            ->orderBy('brand_name')
            ->get()
            ->map(function (CompanyProduct $p) use ($onHand, $loose, $business) {
                $held = (int) ($onHand[$p->id] ?? 0);
                $pieces = (int) ($loose[$p->id] ?? 0);
                $rate = $p->purchase_rate ?? Money::zero();

                return [
                    'product' => $p->label(),
                    'code' => $p->code ?? '',
                    'company' => $p->company?->name ?? '—',
                    'on_hand' => $held,
                    'loose' => $pieces,
                    'reorder_level' => (int) $p->reorder_level,
                    'short_by' => max(0, (int) $p->reorder_level - $held),
                    'rate' => $rate,
                    'value' => $rate->times($held)->plus(PosBillLine::piece($rate, max(1, (int) $p->units_per_pack))->times($pieces)),
                    'trade_value' => ($p->sellingPriceFor($business) ?? Money::zero())->times($held),
                ];
            })
            ->filter(fn ($row) => $reorderOnly
                ? $row['on_hand'] <= $row['reorder_level']
                : ($row['on_hand'] !== 0 || $row['loose'] !== 0))
            ->values()->all();

        $columns = [
            $this->col('product', 'Product'), $this->col('code', 'Code'), $this->col('company', 'Company'),
            $this->col('on_hand', 'In stock', 'number'), $this->col('loose', 'Loose pieces', 'number'),
            $this->col('reorder_level', 'Reorder level', 'number'),
        ];

        if ($reorderOnly) {
            $columns[] = $this->col('short_by', 'Below level by', 'number');
            $columns[] = $this->col('rate', 'Purchase rate', 'money');
        } else {
            $columns[] = $this->col('rate', 'Purchase rate', 'money');
            $columns[] = $this->col('value', 'Value at cost', 'money');
            $columns[] = $this->col('trade_value', $business->sellsAtMrp() ? 'Value at MRP' : 'Value at trade price', 'money');
        }

        return [
            'columns' => $columns,
            'rows' => $rows,
            'totals' => $this->totals($rows, $reorderOnly ? [] : ['value', 'trade_value'], ['on_hand']),
        ];
    }
}
