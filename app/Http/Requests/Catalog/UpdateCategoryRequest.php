<?php

namespace App\Http\Requests\Catalog;

class UpdateCategoryRequest extends CategoryRequest
{
    public function rules(): array
    {
        return $this->categoryRules(partial: true);
    }
}
