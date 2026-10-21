<?php

namespace App\Http\Requests\Catalog;

class StoreProductRequest extends ProductRequest
{
    public function rules(): array
    {
        return $this->productRules(partial: false);
    }
}
