<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreProductVariantRequest extends FormRequest
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
            'options' => 'present|array',
            'options.*.id' => 'nullable|integer|exists:product_options,id',
            'options.*.name' => 'required|string|max:100',
            'options.*.position' => 'required|integer|min:0',
            'options.*.values' => 'required|array|min:1',
            'options.*.values.*.id' => 'nullable|integer|exists:product_option_values,id',
            'options.*.values.*.label' => 'required|string|max:100',
            'options.*.values.*.image_url' => 'nullable|string|max:500',
            'options.*.values.*.position' => 'required|integer|min:0',
            'variants' => 'present|array',
            'variants.*.id' => 'nullable|integer|exists:product_variants,id',
            'variants.*.sku' => 'nullable|string|max:100',
            'variants.*.price' => 'nullable|numeric|min:0',
            'variants.*.stock' => 'required|integer|min:0',
            'variants.*.image_url' => 'nullable|string|max:500',
            'variants.*.is_active' => 'required|boolean',
            'variants.*.option_value_ids' => 'required|array',
            'variants.*.option_value_ids.*' => 'integer',
        ];
    }
}
