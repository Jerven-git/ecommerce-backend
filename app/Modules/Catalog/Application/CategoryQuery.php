<?php

namespace App\Modules\Catalog\Application;

use App\Models\Category;
use Illuminate\Database\Eloquent\Collection;

class CategoryQuery
{
    /** @return Collection<int, Category> */
    public function tree(): Collection
    {
        return Category::whereNull('parent_id')
            ->with('childrenRecursive')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();
    }
}
