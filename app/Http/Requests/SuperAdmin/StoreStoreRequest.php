<?php

namespace App\Http\Requests\SuperAdmin;

use App\Rules\StoreCustomDomain;
use Illuminate\Foundation\Http\FormRequest;

class StoreStoreRequest extends FormRequest
{
    use CanonicalisesDomainInput;

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
            'domain' => ['nullable', 'string', 'max:253', new StoreCustomDomain, 'unique:stores,domain'],
        ];
    }

    public function messages(): array
    {
        return [
            'slug.regex' => 'Slug may only contain lowercase letters, numbers, and dashes.',
            'slug.unique' => 'A store with that slug already exists.',
            'domain.unique' => 'That domain is already claimed by another store.',
        ];
    }
}
