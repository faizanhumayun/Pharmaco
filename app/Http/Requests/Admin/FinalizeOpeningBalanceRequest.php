<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class FinalizeOpeningBalanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('finalize', $this->route('business')->openingBalance);
    }

    public function rules(): array
    {
        return [
            // The tick is stored verbatim, so what was agreed to is on the record.
            'confirmed' => ['accepted'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function messages(): array
    {
        return [
            'confirmed.accepted' => 'Confirm that these values are the actual opening position before finalizing.',
        ];
    }
}
