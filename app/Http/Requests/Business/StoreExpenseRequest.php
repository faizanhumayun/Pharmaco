<?php

namespace App\Http\Requests\Business;

use App\Models\DailyEntry;
use Illuminate\Foundation\Http\FormRequest;

/**
 * One expense added from the Expenses page. The same limits as the daily form:
 * a day that has happened, after the opening, and not closed.
 */
class StoreExpenseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', [DailyEntry::class, $this->route('business')]);
    }

    public function rules(): array
    {
        $business = $this->route('business');

        $earliest = collect([
            $business->locked_through_date?->copy()->addDay(),
            $business->opening_date?->copy()->addDay(),
        ])->filter()->max();

        return [
            'business_date' => array_values(array_filter([
                'required', 'date',
                'before_or_equal:' . $business->today()->toDateString(),
                $earliest ? 'after_or_equal:' . $earliest->toDateString() : null,
            ])),
            'category' => ['required', 'string', 'max:80'],
            'description' => ['nullable', 'string', 'max:255'],
            'amount' => ['required', 'string', 'regex:/^[\d,]*\.?\d*$/', 'not_in:0,0.00,0.0'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['amount' => str_replace([',', ' '], '', (string) $this->input('amount'))]);
    }

    public function messages(): array
    {
        return [
            'business_date.before_or_equal' => 'That day has not happened yet.',
            'business_date.after_or_equal' => 'That day is closed, or falls on or before the opening date. Reopen it first.',
            'category.required' => 'Say which head this expense belongs under.',
            'amount.not_in' => 'Enter how much was spent.',
            'amount.regex' => 'Enter a plain amount, for example 1500 or 1,500.00',
        ];
    }
}
