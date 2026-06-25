<?php

namespace App\Http\Requests\SuperAdmin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isSuperAdmin() ?? false;
    }

    public function rules(): array
    {
        $storeId = $this->route('store')?->id;

        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'slug' => [
                'sometimes',
                'required',
                'string',
                'max:255',
                'regex:/^[a-z0-9-]+$/',
                Rule::unique('stores', 'slug')->ignore($storeId),
            ],
            'status' => ['sometimes', 'in:active,inactive'],
            'default_currency_id' => ['sometimes', 'nullable', 'integer', 'exists:currencies,id'],
            'domain' => [
                'nullable',
                'string',
                'max:255',
                'regex:/^[a-z0-9]([a-z0-9.-]*[a-z0-9])?$/i',
                Rule::unique('stores', 'domain')->ignore($storeId),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'slug.regex' => 'Slug may only contain lowercase letters, numbers, and dashes.',
            'slug.unique' => 'A store with that slug already exists.',
        ];
    }
}
