<?php

namespace App\Http\Requests\Catalog;

class UpdateProductRequest extends ProductRequest
{
    public function rules(): array
    {
        return $this->productRules(partial: true);
    }
}
