<?php

namespace App\Http\Requests\SuperAdmin;

use App\Rules\StoreCustomDomain;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateStoreRequest extends FormRequest
{
    use CanonicalisesDomainInput {
        prepareForValidation as prepareDomainForValidation;
    }

    protected function prepareForValidation(): void
    {
        $this->prepareDomainForValidation();

        if (! $this->has('timezone') || ! is_string($this->input('timezone'))) {
            return;
        }

        $timezone = trim($this->input('timezone'));
        $this->merge(['timezone' => $timezone === '' ? null : $timezone]);
    }

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
            'timezone' => ['sometimes', 'nullable', 'string', 'max:64', 'timezone:all'],
            'domain' => [
                'nullable',
                'string',
                'max:253',
                new StoreCustomDomain,
                Rule::unique('stores', 'domain')->ignore($storeId),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'slug.regex' => 'Slug may only contain lowercase letters, numbers, and dashes.',
            'slug.unique' => 'A store with that slug already exists.',
            'domain.unique' => 'That domain is already claimed by another store.',
            'timezone.timezone' => 'Choose a valid IANA timezone, such as Asia/Singapore.',
        ];
    }
}
