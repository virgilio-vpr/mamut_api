<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'username' => ['nullable', 'string', 'max:255', Rule::unique('users', 'username')],
            'phone' => ['nullable', 'string', 'max:30'],
            'password' => ['required', 'string', 'min:12'],
            'sector_id' => [
                'sometimes',
                'nullable',
                'integer',
                Rule::exists('sectors', 'id')->whereNull('deleted_at'),
            ],
            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => [
                'required',
                'string',
                'distinct',
                Rule::exists('roles', 'name')->where('guard_name', 'web'),
            ],
        ];
    }
}
