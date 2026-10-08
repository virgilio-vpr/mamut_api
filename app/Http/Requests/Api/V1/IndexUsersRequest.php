<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexUsersRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'role' => [
                'sometimes',
                'string',
                Rule::exists('roles', 'name')->where('guard_name', 'web'),
            ],
            'sector_id' => [
                'sometimes',
                'integer',
                Rule::exists('sectors', 'id')->whereNull('deleted_at'),
            ],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
