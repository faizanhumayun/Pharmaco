<?php

namespace App\Http\Requests\Business;

use App\Models\Company;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manageOrders', $this->route('business'));
    }

    public function rules(): array
    {
        $business = $this->route('business');

        return [
            // Checked against this business's own companies, never taken on trust.
            'company_id' => [
                $this->route('order') ? 'nullable' : 'required',
                Rule::exists('companies', 'id')->where('business_id', $business->id),
            ],

            'business_date' => ['required', 'date', 'before_or_equal:'.$business->today()->addDay()->toDateString()],
            'discount_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'notes' => ['nullable', 'string', 'max:2000'],

            'lines' => ['required', 'array', 'min:1', 'max:300'],
            'lines.*.company_product_id' => ['nullable', 'integer'],
            'lines.*.brand_name' => ['nullable', 'string', 'max:200'],

            // Carried only so the form can be redrawn as it was if something
            // else fails validation. The action reads none of them — every
            // description on a saved line comes from the catalogue.
            'lines.*.label' => ['nullable', 'string', 'max:300'],
            'lines.*.generic_name' => ['nullable', 'string', 'max:255'],
            'lines.*.pack_size' => ['nullable', 'string', 'max:60'],
            'lines.*.cartons' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'lines.*.packs' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'lines.*.case_size' => ['nullable', 'integer', 'min:1'],
            'lines.*.rate' => ['nullable', 'string', 'regex:/^[\d,]*\.?\d*$/'],
            'lines.*.discount_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ];
    }

    public function messages(): array
    {
        return [
            'lines.required' => 'Add at least one product to the order.',
            'lines.min' => 'Add at least one product to the order.',
            'company_id.required' => 'Choose the company this order is for.',
            'company_id.exists' => 'That company does not belong to this business.',
            'discount_percent.max' => 'A discount cannot be more than 100%.',
        ];
    }

    /** The company the order is for, from the form or from the order being edited. */
    public function company(): Company
    {
        $order = $this->route('order');

        if ($order !== null && $this->input('company_id') === null) {
            return $order->company;
        }

        return Company::forBusiness($this->route('business'))->findOrFail($this->input('company_id'));
    }

    /** @return array<string, mixed> */
    public function orderData(): array
    {
        return [
            'business_date' => $this->validated('business_date'),
            'discount_percent' => $this->input('discount_percent'),
            'notes' => $this->input('notes'),
            'lines' => $this->input('lines', []),
        ];
    }
}
