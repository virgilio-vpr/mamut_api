<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => [
                'sometimes',
                'required',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($this->route('user')),
            ],
            'username' => [
                'sometimes',
                'nullable',
                'string',
                'max:255',
                Rule::unique('users', 'username')->ignore($this->route('user')),
            ],
            'phone' => ['sometimes', 'nullable', 'string', 'max:30'],
            'password' => ['sometimes', 'required', 'string', 'min:12'],
            'sector_id' => [
                'sometimes',
                'nullable',
                'integer',
                Rule::exists('sectors', 'id')->whereNull('deleted_at'),
            ],
            'roles' => ['sometimes', 'required', 'array', 'min:1'],
            'roles.*' => [
                'required',
                'string',
                'distinct',
                Rule::exists('roles', 'name')->where('guard_name', 'web'),
            ],
        ];
    }
}
