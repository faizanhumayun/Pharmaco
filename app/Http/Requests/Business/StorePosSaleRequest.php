<?php

namespace App\Http\Requests\Business;

use Illuminate\Foundation\Http\FormRequest;

/** One bill from the counter: what was sold, and what was taken for it. */
class StorePosSaleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('sellAtPos', $this->route('business'));
    }

    public function rules(): array
    {
        return [
            'items' => ['required', 'array', 'min:1', 'max:200'],
            'items.*.product_id' => ['required', 'integer'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:100000'],
            'items.*.unit_price' => ['required', 'string', 'regex:/^\d+(\.\d{1,2})?$/'],

            'received' => ['nullable', 'string', 'regex:/^\d+(\.\d{1,2})?$/'],
            'discount' => ['nullable', 'string', 'regex:/^\d+(\.\d{1,2})?$/'],
            'customer_name' => ['nullable', 'string', 'max:120'],
            'note' => ['nullable', 'string', 'max:255'],
        ];
    }

    protected function prepareForValidation(): void
    {
        foreach (['received', 'discount'] as $field) {
            if ($this->has($field)) {
                $this->merge([$field => str_replace([',', ' '], '', (string) $this->input($field))]);
            }
        }
    }

    public function messages(): array
    {
        return [
            'items.required' => 'Add something to the bill first.',
            'items.*.unit_price.regex' => 'Enter a plain price, for example 120 or 120.50',
        ];
    }
}
