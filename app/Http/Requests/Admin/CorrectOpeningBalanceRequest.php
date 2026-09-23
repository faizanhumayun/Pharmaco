<?php

namespace App\Http\Requests\Admin;

use App\Domain\Opening\OpeningField;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CorrectOpeningBalanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('correct', $this->route('business')->openingBalance);
    }

    public function rules(): array
    {
        return [
            'account' => ['required', Rule::in(array_map(
                fn (OpeningField $f) => $f->key(),
                OpeningField::all()
            ))],
            'amount' => ['required', 'string', 'regex:/^-?[\d,]*\.?\d*$/', 'not_in:0,0.00'],
            // Mandatory, not conventional: a correction without a stated reason
            // is indistinguishable from history being quietly rewritten.
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['amount' => str_replace([',', ' '], '', (string) $this->input('amount'))]);
    }

    public function messages(): array
    {
        return [
            'reason.required' => 'Say why this correction is being made. It goes on the permanent record.',
            'amount.not_in' => 'A correction of zero changes nothing.',
        ];
    }
}
