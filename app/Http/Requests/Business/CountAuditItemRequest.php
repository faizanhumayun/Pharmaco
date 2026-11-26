<?php

namespace App\Http\Requests\Business;

use Illuminate\Foundation\Http\FormRequest;

/**
 * One product counted on a company audit's sheet: it matches, or what is on
 * the rack — in packs and loose pieces, or batch by batch.
 */
class CountAuditItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('verifyStock', $this->route('business'));
    }

    public function rules(): array
    {
        return [
            'agrees' => ['nullable', 'boolean'],
            // Whole packs, or part of one where packs are opened: 0.7 of a
            // pack of 28 is 20 tablets. Turned into packs and pieces later.
            'packs' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
            'loose' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'note' => ['nullable', 'string', 'max:255'],
            'batches' => ['nullable', 'array', 'max:200'],
            'batches.*.batch_no' => ['nullable', 'string', 'max:60'],
            'batches.*.expires_on' => ['nullable', 'regex:/^\d{4}-\d{2}(-\d{2})?$/'],
            'batches.*.packs' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
            'batches.*.loose' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            // Each batch's own prices: what it was bought at, what it sells for.
            'batches.*.unit_cost' => ['nullable', 'regex:/^\d+(\.\d{1,2})?$/'],
            'batches.*.mrp' => ['nullable', 'regex:/^\d+(\.\d{1,2})?$/'],
            'batches.*.is_new' => ['nullable', 'boolean'],
            // The product as the carton shows it, corrected while counting.
            'pack_size' => ['nullable', 'string', 'max:60'],
            // The product's name and type, put right while it is in hand.
            'brand_name' => ['nullable', 'string', 'max:160'],
            'strength' => ['nullable', 'string', 'max:60'],
            'dosage_form' => ['nullable', \Illuminate\Validation\Rule::in(array_map(fn (\App\Enums\DosageForm $f) => $f->label(), \App\Enums\DosageForm::cases()))],
            'purchase_rate' => ['nullable', 'regex:/^\d+(\.\d{1,2})?$/'],
            'mrp' => ['nullable', 'regex:/^\d+(\.\d{1,2})?$/'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $plain = fn ($v) => $v === null ? null : str_replace([',', ' '], '', (string) $v);

        foreach (['purchase_rate', 'mrp'] as $field) {
            if ($this->has($field)) {
                $this->merge([$field => $plain($this->input($field))]);
            }
        }

        if (is_array($this->input('batches'))) {
            $this->merge(['batches' => array_map(fn ($b) => is_array($b) ? array_merge($b, [
                'unit_cost' => $plain($b['unit_cost'] ?? null),
                'mrp' => $plain($b['mrp'] ?? null),
            ]) : $b, $this->input('batches'))]);
        }

        // "Nos", or the 0 the screen shows for it, is no pack size: not a change.
        if (preg_match('/^(0|nos\.?)$/i', trim((string) $this->input('pack_size')))) {
            $this->merge(['pack_size' => null]);
        }
    }

    public function messages(): array
    {
        return [
            'batches.*.expires_on.regex' => 'Enter the expiry as a month and a year.',
            'packs.numeric' => 'Enter the quantity as a number, such as 2 or 0.7.',
            'batches.*.packs.numeric' => 'Enter the quantity as a number, such as 2 or 0.7.',
            'purchase_rate.regex' => 'Enter the purchase rate as a plain amount, such as 120 or 120.50.',
            'batches.*.unit_cost.regex' => 'Enter each purchase rate as a plain amount, such as 120 or 120.50.',
            'batches.*.mrp.regex' => 'Enter each MRP as a plain amount, such as 120 or 120.50.',
            'mrp.regex' => 'Enter the MRP as a plain amount, such as 120 or 120.50.',
            'dosage_form.in' => 'Pick the type from the list.',
        ];
    }

    /** @return array{agrees: bool, packs: ?string, loose: ?int, batches: ?array, note: ?string, product: array<string, string>} */
    public function countData(): array
    {
        $data = $this->validated();

        return [
            'agrees' => $this->boolean('agrees'),
            'packs' => isset($data['packs']) ? (string) $data['packs'] : null,
            'loose' => isset($data['loose']) ? (int) $data['loose'] : null,
            'batches' => $data['batches'] ?? null,
            'note' => $data['note'] ?? null,
            // Only what was sent: a field left out is not a change.
            'product' => array_filter([
                'brand_name' => isset($data['brand_name']) ? trim($data['brand_name']) : null,
                // Strength may be cleared on purpose: sent empty, kept as such.
                'strength' => $this->has('strength') ? trim((string) $this->input('strength')) : null,
                'dosage_form' => $data['dosage_form'] ?? null,
                'pack_size' => $data['pack_size'] ?? null,
                'purchase_rate' => $data['purchase_rate'] ?? null,
                'mrp' => $data['mrp'] ?? null,
            ], fn ($v, $k) => $v !== null && ($v !== '' || $k === 'strength'), ARRAY_FILTER_USE_BOTH),
        ];
    }
}
