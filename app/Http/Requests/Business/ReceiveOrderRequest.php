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
