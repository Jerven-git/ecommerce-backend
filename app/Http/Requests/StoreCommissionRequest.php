<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreCommissionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'customer_name' => 'required|string|max:255',
            'customer_email' => 'required|email:rfc|max:255',
            'customer_phone' => 'nullable|string|max:50',
            'title' => 'required|string|max:255',
            'description' => 'required|string|max:5000',
            'budget_range' => 'nullable|string|max:100',
            'preferred_medium' => 'nullable|string|max:100',
            'preferred_size' => 'nullable|string|max:100',
            'deadline' => 'nullable|date|after:today',
            'reference_image' => 'nullable|file|image|max:5120',
            'recaptcha_token' => 'nullable|string',
        ];
    }
}
