<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'email' => strtolower(trim((string) $this->input('email'))),
            'store_name' => trim((string) $this->input('store_name')),
            'store_slug' => $this->input('store_slug') === null
                ? null
                : strtolower(trim((string) $this->input('store_slug'))),
        ]);
    }

    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'email' => ['required', 'email:rfc', 'max:255', 'unique:users,email'],
            'password' => 'required|string|min:8|confirmed',
            'store_name' => 'required|string|max:255',
            'store_slug' => [
                'nullable',
                'string',
                'max:255',
                'regex:/^[a-z0-9-]+$/',
                'unique:stores,slug',
            ],
            'recaptcha_token' => 'nullable|string',
        ];
    }
}
