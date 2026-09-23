<?php

namespace App\Http\Requests\Business;

use App\Models\Company;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePriceListRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('importProducts', $this->route('business'));
    }

    public function rules(): array
    {
        return [
            // mimetypes is checked against the file's own content, not its
            // extension, so a renamed spreadsheet is refused at the door.
            'file' => ['required', 'file', 'mimes:pdf', 'mimetypes:application/pdf', 'max:25600'],

            // Present only when the company was chosen on the form rather than
            // taken from the URL. Either way it is confirmed against this
            // business's own companies, never trusted as it arrives.
            'company_id' => [
                $this->route('company') ? 'nullable' : 'required',
                Rule::exists('companies', 'id')->where('business_id', $this->route('business')->id),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'file.mimes' => 'The price list must be a PDF.',
            'file.mimetypes' => 'That file is not a PDF, whatever its name says.',
            'file.max' => 'The price list must be under 25 MB.',
            'company_id.required' => 'Choose the company that sent this price list.',
            'company_id.exists' => 'That company does not belong to this business.',
        ];
    }

    /**
     * The company picked on the form, when the URL did not name one.
     *
     * Resolved through the business's own companies rather than by id alone —
     * the validator has already checked it belongs here, and this makes that
     * true twice.
     */
    public function chosenCompany(): ?Company
    {
        $id = $this->validated('company_id');

        return $id === null || $id === ''
            ? null
            : Company::forBusiness($this->route('business'))->find($id);
    }
}
