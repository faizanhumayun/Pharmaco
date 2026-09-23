<?php

namespace App\Http\Requests\Business;

use App\Enums\ProductField;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A correction typed on the review screen.
 *
 * The rules here are deliberately loose: the row normaliser is what decides
 * whether "1,250/50" is a price, and it has to make that decision the same way
 * for a typed value as for a read one. Validation's job is only to keep the
 * strings within the columns that will hold them.
 */
class UpdateImportRowRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('importProducts', $this->route('business'));
    }

    public function rules(): array
    {
        return [
            'values' => ['required', 'array'],
            'values.code' => ['nullable', 'string', 'max:60'],
            'values.brand_name' => ['nullable', 'string', 'max:200'],
            'values.generic_name' => ['nullable', 'string', 'max:255'],
            'values.strength' => ['nullable', 'string', 'max:80'],
            'values.dosage_form' => ['nullable', 'string', 'max:40'],
            'values.pack_size' => ['nullable', 'string', 'max:60'],
            'values.pack_type' => ['nullable', 'string', 'max:40'],
            'values.mrp' => ['nullable', 'string', 'max:30'],
            'values.trade_price' => ['nullable', 'string', 'max:30'],
            'values.purchase_rate' => ['nullable', 'string', 'max:30'],
            'values.case_size' => ['nullable', 'string', 'max:10'],
            'included' => ['nullable', 'boolean'],
        ];
    }

    /** @return array<string, string|null> */
    public function correctedValues(): array
    {
        $values = [];

        foreach (ProductField::cases() as $field) {
            $values[$field->value] = $this->input("values.{$field->value}");
        }

        return $values;
    }
}
