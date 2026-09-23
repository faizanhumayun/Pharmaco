<?php

namespace App\Http\Requests\Admin;

use App\Enums\BusinessType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreBusinessRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', \App\Models\Business::class);
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:160'],
            // Pharmacy mode is visible in the UI as "Coming Soon" and is rejected
            // here as well, so a crafted request cannot create one.
            'business_type' => ['required', Rule::in(array_map(
                fn (BusinessType $t) => $t->value,
                BusinessType::available()
            ))],
            'stock_unit' => ['required', Rule::enum(\App\Enums\StockUnit::class)],
            'currency' => ['required', 'string', 'size:3'],
            'timezone' => ['required', 'string', 'max:64', 'timezone'],
            'address' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:40'],
            'email' => ['nullable', 'email', 'max:160'],
            'ntn' => ['nullable', 'string', 'max:40'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function messages(): array
    {
        return [
            'business_type.in' => 'Only distributor businesses can be created at the moment. Pharmacy support is coming soon.',
        ];
    }
}
