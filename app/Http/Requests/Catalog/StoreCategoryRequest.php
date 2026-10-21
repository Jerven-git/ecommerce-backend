<?php

namespace App\Http\Requests\Catalog;

class StoreCategoryRequest extends CategoryRequest
{
    public function rules(): array
    {
        return $this->categoryRules(partial: false);
    }
}
