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
            // Only the kinds the app supports, whatever a crafted request asks for.
            'business_type' => ['required', Rule::in(array_map(
                fn (BusinessType $t) => $t->value,
                BusinessType::available()
            ))],
            // Optional: left out, a business counts the way its kind usually does.
            'receipt_format' => ['nullable', Rule::enum(\App\Enums\ReceiptFormat::class)],
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
            'business_type.in' => 'Choose a business type from the list.',
        ];
    }
}
