<?php

namespace App\Http\Requests\Business;

use App\Enums\ProductField;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RemapPriceListRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('importProducts', $this->route('business'));
    }

    public function rules(): array
    {
        return [
            'columns' => ['present', 'array', 'max:80'],
            'columns.*' => ['nullable', Rule::in(array_keys(ProductField::options()))],
        ];
    }

    /** @return array<int|string, string|null> */
    public function columnMap(): array
    {
        return $this->input('columns', []);
    }
}
