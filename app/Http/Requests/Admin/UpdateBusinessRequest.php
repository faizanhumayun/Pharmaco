<?php

namespace App\Http\Requests\Admin;

use App\Enums\BusinessType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateBusinessRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('business'));
    }

    /**
     * Put the locked fields back before anything is validated.
     *
     * Currency and timezone are frozen once a business has financial history —
     * changing the timezone would reinterpret which calendar day every posted
     * transaction fell on. The form disables both, and a disabled control is
     * not submitted at all, so they arrive missing and fail their "required"
     * rule even though nobody was trying to change them.
     *
     * Taking them from the record rather than from the request fixes that and
     * closes the other half of it too: a hand-made POST cannot set them either,
     * because whatever it sends is overwritten here.
     */
    protected function prepareForValidation(): void
    {
        $business = $this->route('business');

        if ($business?->opening_date !== null) {
            $this->merge([
                'currency' => $business->currency,
                'timezone' => $business->timezone,
            ]);
        }
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:160'],
            'business_type' => ['required', Rule::in(array_map(
                fn (BusinessType $t) => $t->value,
                BusinessType::available()
            ))],
            // Optional: left out, a business counts the way its kind usually does.
            'stock_unit' => ['nullable', Rule::enum(\App\Enums\StockUnit::class)],
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
}
