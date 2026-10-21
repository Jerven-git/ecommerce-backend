<?php

namespace App\Http\Requests\Catalog;

use App\Support\Tenancy\CurrentStore;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

abstract class ProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    protected function productRules(bool $partial): array
    {
        $required = $partial ? 'sometimes' : 'required';
        $categoryExists = Rule::exists('categories', 'id')->where(
            fn ($query) => $query->where('store_id', app(CurrentStore::class)->id()),
        );

        $rules = [
            'name' => "{$required}|string|max:255",
            'description' => 'nullable|string',
            'price' => "{$required}|numeric|min:0",
            'image' => 'nullable|image|max:2048',
            'hover_image' => 'nullable|image|max:2048',
            'material' => 'nullable|string|max:255',
            'dimensions' => 'nullable|string|max:100',
            'stock' => 'nullable|integer|min:0',
            'weight' => 'nullable|numeric|min:0',
            'length_cm' => 'nullable|numeric|min:0',
            'width_cm' => 'nullable|numeric|min:0',
            'height_cm' => 'nullable|numeric|min:0',
            'shipping_calc_type' => 'nullable|in:weight,dimensions',
            'category' => 'nullable|string',
            'category_id' => ['nullable', $categoryExists],
            'category_ids' => 'nullable|array',
            'category_ids.*' => [$categoryExists],
            'is_active' => 'nullable|boolean',
            'hover_zoom_enabled' => 'nullable|boolean',
            'allow_backorder' => 'nullable|boolean',
            'backorder_charge_policy' => 'nullable|in:charged_now,charged_later',
            'seo_title' => 'nullable|string|max:255',
            'seo_description' => 'nullable|string|max:500',
            'og_image_url' => 'nullable|url|max:500',
            'noindex' => 'nullable|boolean',
        ];

        if ($partial) {
            $rules['image_url'] = 'nullable|string';
            $rules['hover_image_url'] = 'nullable|string';
        }

        return $rules;
    }
}
