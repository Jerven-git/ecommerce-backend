<?php

namespace App\Http\Requests\Catalog;

use App\Support\Tenancy\CurrentStore;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

abstract class CategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    protected function categoryRules(bool $partial): array
    {
        $required = $partial ? 'sometimes|required' : 'required';

        return [
            'name' => "{$required}|string|max:255",
            'parent_id' => [
                'nullable',
                Rule::exists('categories', 'id')->where(
                    fn ($query) => $query->where('store_id', app(CurrentStore::class)->id()),
                ),
            ],
            'sort_order' => 'nullable|integer|min:0',
            'image' => 'nullable|image|max:5120',
            'image_url' => 'nullable|string|max:500',
            'overlay_opacity' => 'nullable|integer|min:0|max:100',
        ];
    }
}
