<?php

namespace App\Http\Requests\Business;

use App\Enums\BusinessRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTeamMemberRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manageMembers', $this->route('business'));
    }

    public function rules(): array
    {
        return [
            'role' => ['required', Rule::enum(BusinessRole::class)],
            'is_active' => ['required', 'boolean'],
        ];
    }
}
