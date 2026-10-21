<?php

namespace App\Http\Requests\Catalog;

use App\Support\Tenancy\CurrentStore;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReorderCategoriesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'ids' => 'required|array|min:1',
            'ids.*' => [
                'integer',
                Rule::exists('categories', 'id')->where(
                    fn ($query) => $query->where('store_id', app(CurrentStore::class)->id()),
                ),
            ],
        ];
    }
}
