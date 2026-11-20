<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSecurityCodeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage-platform');
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['code' => trim((string) $this->input('code'))]);
    }

    public function rules(): array
    {
        return [
            // Four digits, to match the four boxes on the code page.
            'code' => ['required', 'digits:4', Rule::unique('security_codes', 'code')],
            'label' => ['nullable', 'string', 'max:120'],
        ];
    }
}
