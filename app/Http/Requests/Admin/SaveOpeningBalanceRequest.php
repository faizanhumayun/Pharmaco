<?php

namespace App\Http\Requests\Admin;

use App\Domain\Opening\OpeningField;
use App\Models\Business;
use Illuminate\Foundation\Http\FormRequest;

class SaveOpeningBalanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        $business = $this->route('business');
        $opening = $business->openingBalance;

        return $opening
            ? $this->user()->can('update', $opening)
            : $this->user()->can('create', [\App\Models\OpeningBalance::class, $business]);
    }

    public function rules(): array
    {
        $rules = [
            'opening_date' => ['required', 'date', 'before_or_equal:today'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];

        foreach (OpeningField::all() as $field) {
            // Money arrives as a grouped string from the form. It is validated
            // as a shape here and converted through Money, never through a float.
            $rules[$field->key()] = [
                $field->required ? 'required' : 'nullable',
                'string',
                'regex:/^-?[\d,]*\.?\d*$/',
            ];
        }

        return $rules;
    }

    protected function prepareForValidation(): void
    {
        $clean = [];

        foreach (OpeningField::all() as $field) {
            $value = $this->input($field->key());
            $clean[$field->key()] = $value === null || trim((string) $value) === ''
                ? '0'
                : str_replace([',', ' '], '', (string) $value);
        }

        $this->merge($clean);
    }

    public function messages(): array
    {
        return [
            'opening_date.before_or_equal' => 'The opening date cannot be in the future.',
            '*.regex' => 'Enter a plain amount, for example 1000000 or 1,000,000.00',
        ];
    }

    public function attributes(): array
    {
        return collect(OpeningField::all())
            ->mapWithKeys(fn (OpeningField $f) => [$f->key() => strtolower($f->label)])
            ->all();
    }
}
