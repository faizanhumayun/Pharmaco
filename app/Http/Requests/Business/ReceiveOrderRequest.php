<?php

namespace App\Http\Requests\Business;

use Illuminate\Foundation\Http\FormRequest;

class ReceiveOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manageOrders', $this->route('business'));
    }

    public function rules(): array
    {
        return [
            // Keyed by order line id. A line left blank means nothing arrived
            // for it, which is a legitimate answer rather than a missing one.
            'lines' => ['nullable', 'array'],
            'lines.*.cartons' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'lines.*.packs' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'lines.*.note' => ['nullable', 'string', 'max:255'],

            'extras' => ['nullable', 'array', 'max:100'],
            'extras.*.company_product_id' => ['nullable', 'integer'],
            // The catalogue figures a delivery confirms.
            'extras.*.mrp' => ['nullable', 'string', 'max:30'],
            'extras.*.trade' => ['nullable', 'string', 'max:30'],
            // A month: cartons are printed "09/2027", not to the day.
            'extras.*.expires_on' => ['nullable', 'date_format:Y-m'],
            'lines.*.expires_on' => ['nullable', 'date_format:Y-m'],
            'extras.*.batch_no' => ['nullable', 'string', 'max:60'],
            'lines.*.batch_no' => ['nullable', 'string', 'max:60'],
            'extras.*.brand_name' => ['nullable', 'string', 'max:200'],
            'extras.*.label' => ['nullable', 'string', 'max:300'],
            'extras.*.generic_name' => ['nullable', 'string', 'max:255'],
            'extras.*.pack_size' => ['nullable', 'string', 'max:60'],
            'extras.*.case_size' => ['nullable', 'integer', 'min:1'],
            'extras.*.cartons' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'extras.*.packs' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'extras.*.rate' => ['nullable', 'string', 'regex:/^[\d,]*\.?\d*$/'],
            'extras.*.note' => ['nullable', 'string', 'max:255'],

            // The supplier's invoice. Optional: a delivery can be checked in
            // before the bill is to hand.
            'invoice_no' => ['nullable', 'string', 'max:60'],
            'paid' => ['nullable', 'string', 'regex:/^[\d,]*\.?\d*$/'],
        ];
    }

    /**
     * Whatever arrived has to say when it expires.
     *
     * Stock with no date can never be warned about, so none is taken in
     * without one. A line nothing came for has no date to give. Every line
     * short of one is named, rather than only the first.
     *
     * @return list<\Closure>
     */
    public function after(): array
    {
        return [function (\Illuminate\Validation\Validator $validator) {
            $has = fn (array $row, string $key) => (int) ($row[$key] ?? 0) > 0;
            $blank = fn (array $row) => trim((string) ($row['expires_on'] ?? '')) === '';
            $names = $this->route('order')?->lines->keyBy('id') ?? collect();

            foreach ((array) $this->input('lines', []) as $id => $row) {
                if (is_array($row) && ($has($row, 'packs') || $has($row, 'cartons')) && $blank($row)) {
                    $validator->errors()->add('expires_on', sprintf(
                        '%s: enter the expiry printed on the pack.',
                        $names->get($id)?->label() ?? 'A line',
                    ));
                }
            }

            foreach ((array) $this->input('extras', []) as $row) {
                if (is_array($row) && ($has($row, 'packs') || $has($row, 'cartons') || $has($row, 'loose')) && $blank($row)) {
                    $validator->errors()->add('expires_on', sprintf(
                        '%s: enter the expiry printed on the pack.',
                        \App\Models\CompanyProduct::forBusiness($this->route('business'))
                            ->find((int) ($row['company_product_id'] ?? 0))?->label() ?? 'An extra item that was not on the order',
                    ));
                }
            }
        }];
    }

    /** @return array<string, mixed> */
    public function receiptData(): array
    {
        return [
            'lines' => $this->input('lines', []),
            'extras' => array_values($this->input('extras', [])),
            'invoice_no' => $this->input('invoice_no'),
            // bill_amount is deliberately absent: the action derives it from
            // the delivery rather than believing the form.
            'paid' => $this->input('paid'),
        ];
    }
}
