<?php

namespace App\Http\Requests\Business;

use App\Enums\BusinessRole;
use App\Models\DailyEntry;
use App\Support\Money;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class SaveDailyEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', [DailyEntry::class, $this->route('business')]);
    }

    public function rules(): array
    {
        $business = $this->route('business');
        // Earliest allowed day: after the lock, and always after the opening
        // date — the opening position already covers everything up to and
        // including that day, so activity on it would be counted twice.
        $earliest = collect([
            $business->locked_through_date?->copy()->addDay(),
            $business->opening_date?->copy()->addDay(),
        ])->filter()->max();

        return array_merge([
            'business_date' => array_values(array_filter([
                'required', 'date',
                'before_or_equal:'.$business->today()->toDateString(),
                $earliest ? 'after_or_equal:'.$earliest->toDateString() : null,
            ])),
            'company_note' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],

            // Not a stored column: the day's total is typed and credit sales
            // fall out of it, so the two figures cannot contradict each other.
            'sale_total' => ['nullable', 'string', 'regex:/^[\d,]*\.?\d*$/'],

            'purchases' => ['nullable', 'array', 'max:50'],
            'purchases.*.company' => ['nullable', 'string', 'max:160'],
            'purchases.*.order_id' => ['nullable', 'integer'],
            'purchases.*.invoice_no' => ['nullable', 'string', 'max:60'],
            'purchases.*.amount' => ['nullable', 'string', 'regex:/^[\d,]*\.?\d*$/'],
            'purchases.*.paid' => ['nullable', 'string', 'regex:/^[\d,]*\.?\d*$/'],

            'sales' => ['nullable', 'array', 'max:100'],
            'sales.*.pharmacy' => ['nullable', 'string', 'max:160'],
            'sales.*.invoice_no' => ['nullable', 'string', 'max:60'],
            'sales.*.amount' => ['nullable', 'string', 'regex:/^[\d,]*\.?\d*$/'],
            'sales.*.received' => ['nullable', 'string', 'regex:/^[\d,]*\.?\d*$/'],

            'expenses' => ['nullable', 'array', 'max:50'],
            'expenses.*.category' => ['nullable', 'string', 'max:80'],
            'expenses.*.description' => ['nullable', 'string', 'max:255'],
            'expenses.*.amount' => ['nullable', 'string', 'regex:/^[\d,]*\.?\d*$/'],
        ], array_fill_keys(
            DailyEntry::MONEY_FIELDS,
            ['nullable', 'string', 'regex:/^[\d,]*\.?\d*$/']
        ));
    }

    protected function prepareForValidation(): void
    {
        $purchases = collect($this->input('purchases', []))
            ->map(fn ($row) => [
                'company' => trim((string) ($row['company'] ?? '')),
                'order_id' => ($row['order_id'] ?? '') === '' ? null : (int) $row['order_id'],
                'invoice_no' => trim((string) ($row['invoice_no'] ?? '')) ?: null,
                'amount' => $this->normalise($row['amount'] ?? null),
                'paid' => $this->normalise($row['paid'] ?? null),
            ])
            ->reject(fn ($row) => $this->isBlank($row['amount']) && $this->isBlank($row['paid']))
            ->values()
            ->all();

        // Listed invoices are the record; the header totals follow from them.
        if ($purchases !== []) {
            $this->merge([
                'purchase_total' => $this->total($purchases, 'amount'),
                'purchase_paid' => $this->total($purchases, 'paid'),
            ]);
        }

        $this->merge(['purchases' => $purchases]);

        $sales = collect($this->input('sales', []))
            ->map(fn ($row) => [
                'pharmacy' => trim((string) ($row['pharmacy'] ?? '')),
                'invoice_no' => trim((string) ($row['invoice_no'] ?? '')) ?: null,
                'amount' => $this->normalise($row['amount'] ?? null),
                'received' => $this->normalise($row['received'] ?? null),
            ])
            ->reject(fn ($row) => $this->isBlank($row['amount']) && $this->isBlank($row['received']))
            ->values()
            ->all();

        /*
         * The same rule on the selling side. Cash is only what today's invoices
         * actually covered — anything taken beyond them is recovery of earlier
         * credit, and counting it as sales would inflate both revenue and the
         * margin that values the stock.
         */
        if ($sales !== []) {
            $covered = array_map(
                fn ($r) => bccomp($r['received'], $r['amount'], 2) === 1 ? $r['amount'] : $r['received'],
                $sales,
            );

            $this->merge([
                'sale_total' => $this->total($sales, 'amount'),
                'sale_cash' => array_reduce($covered, fn ($carry, $v) => bcadd($carry, $v, 2), '0.00'),
            ]);
        }

        $this->merge(['sales' => $sales]);

        $expenses = collect($this->input('expenses', []))
            ->map(fn ($row) => [
                'category' => trim((string) ($row['category'] ?? '')),
                'description' => trim((string) ($row['description'] ?? '')) ?: null,
                'amount' => $this->normalise($row['amount'] ?? null),
            ])
            ->reject(fn ($row) => $this->isBlank($row['amount']) || $row['category'] === '')
            ->values()
            ->all();

        // Heads named are the record; the day's expense total follows from them.
        if ($expenses !== []) {
            $this->merge(['expenses_cash' => $this->total($expenses, 'amount')]);
        }

        $this->merge(['expenses' => $expenses]);

        // Credit sales are derived in SaveDailyEntry, not here, so the rule
        // holds for callers that never touch a form. The total is normalised
        // for validation and passed straight through.
        if ($this->has('sale_total')) {
            $this->merge([
                'sale_total' => str_replace([',', ' '], '', (string) $this->input('sale_total')) ?: '0',
            ]);
        }

        $clean = [];

        foreach (DailyEntry::MONEY_FIELDS as $field) {
            $value = $this->input($field);
            $clean[$field] = $value === null || trim((string) $value) === ''
                ? '0'
                : str_replace([',', ' '], '', (string) $value);
        }

        $this->merge($clean);
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            $get = fn (string $f) => Money::of($this->input($f) ?: '0');

            // Hard blocks are for things that are definitely wrong. Things that
            // are merely unusual are warnings on the review screen instead —
            // blocking a legitimate transaction teaches people to work around
            // the system.
            // Paying more than the goods cost is allowed on purpose: the excess
            // settles what was already owed to that company.
            if ($get('purchase_discount')->greaterThan($get('purchase_total'))) {
                $v->errors()->add('purchase_discount',
                    'The trade discount cannot exceed the purchase it applies to.');
            }

            $totalSales = $this->has('sale_total')
                ? Money::of($this->input('sale_total') ?: '0')
                : $get('sale_cash')->plus($get('sale_credit'));

            if ($this->has('sale_total')) {
                $entered = $totalSales;

                if ($get('sale_cash')->greaterThan($entered)) {
                    $v->errors()->add('sale_cash',
                        'Cash collected cannot exceed the day\'s sales. Record a recovery against '
                        .'earlier credit under Market collections instead.');
                }
            }

            if ($get('sales_return')->greaterThan($totalSales)) {
                $v->errors()->add('sales_return', "Returns cannot exceed the day's sales.");
            }

            if ($get('gross_profit')->greaterThan($totalSales->minus($get('sales_return')))) {
                $v->errors()->add('gross_profit',
                    'Gross profit cannot exceed net sales — that would make cost of goods negative.');
            }

            // A posted day is amendable while it is open; the date rules above
            // already refuse anything on or before the close.

            // The form is filled with whatever the day it was opened for already
            // holds. Changing the date afterwards would save those figures under
            // the new date — the same day's business counted twice. The form
            // reloads on a date change; this catches it when that did not happen.
            $loadedFor = $this->input('loaded_for');

            if ($loadedFor !== null && $loadedFor !== $this->input('business_date')) {
                $v->errors()->add('business_date',
                    'The date was changed after this form was filled in for '
                    .\Illuminate\Support\Carbon::parse($loadedFor)->format('d M Y')
                    .'. Nothing was saved. Open the form for the new date and enter its figures there.');
            }

            // An operator entering last week's business is usually a mistake.
            if ($this->user()->roleIn($this->route('business')) === BusinessRole::Operator) {
                $date = $this->date('business_date');
                $limit = $this->route('business')->today()->subDays(2);

                if ($date && $date->lessThan($limit)) {
                    $v->errors()->add('business_date',
                        'Operators can only enter the last two days. Ask the owner for anything older.');
                }
            }
        });
    }

    public function messages(): array
    {
        return [
            'business_date.before_or_equal' => 'A business day cannot be recorded before it has happened.',
            'business_date.after_or_equal' => 'That day is closed, or falls on or before the opening '
                .'date. Post an adjustment in the open period instead.',
            '*.regex' => 'Enter a plain amount, for example 150000 or 150,000.00',
        ];
    }

    /** A typed amount as a plain fixed-point string. */
    private function normalise(mixed $value): string
    {
        $clean = str_replace([',', ' '], '', (string) ($value ?? ''));

        return $clean === '' ? '0' : $clean;
    }

    private function isBlank(string $amount): bool
    {
        return ! is_numeric($amount) || bccomp($amount, '0', 2) === 0;
    }

    /**
     * Adds a column of typed amounts.
     *
     * bcadd rather than array_sum: a float total of enough invoices drifts, and
     * the drift lands in a figure the ledger is expected to balance against.
     *
     * @param  array<int, array<string, string>>  $rows
     */
    private function total(array $rows, string $key): string
    {
        return array_reduce(
            $rows,
            fn ($carry, $row) => is_numeric($row[$key]) ? bcadd($carry, $row[$key], 2) : $carry,
            '0.00',
        );
    }
}
