<?php

namespace App\Http\Requests\SuperAdmin;

use Illuminate\Foundation\Http\FormRequest;

class StoreStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isSuperAdmin() ?? false;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:255', 'unique:stores,slug', 'regex:/^[a-z0-9-]+$/'],
            'status' => ['sometimes', 'in:active,inactive'],
            'default_currency_id' => ['nullable', 'integer', 'exists:currencies,id'],
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
