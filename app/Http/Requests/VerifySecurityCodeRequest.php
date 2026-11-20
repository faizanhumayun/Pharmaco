<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class VerifySecurityCodeRequest extends FormRequest
{
    /** Anyone may try a code: this page is in front of sign-in. */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['security_code' => ['required', 'string', 'max:255']];
    }
}
