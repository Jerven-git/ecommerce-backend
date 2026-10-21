<?php

namespace App\Modules\Catalog\Application;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Http\Request;

class ProductQuery
{
    /** @return array<string, mixed> */
    public function list(Request $request): array
    {
        $query = Product::with('categories')->withCount('variants')->withSum('activeVariants', 'stock');

        if ($request->has('ids')) {
            $ids = collect(explode(',', (string) $request->input('ids')))
                ->map(fn ($id) => (int) trim($id))
                ->filter(fn ($id) => $id > 0)
                ->take(100)
                ->values()
                ->all();

            if (empty($ids)) {
                return ['data' => []];
            }

            $query->whereIn('id', $ids);
        }

        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        if ($request->has('category_id')) {
            $categoryId = (int) $request->input('category_id');
            $category = Category::with('childrenRecursive')->find($categoryId);
            $categoryIds = $category
                ? array_merge([$categoryId], $category->allDescendantIds())
                : [$categoryId];
            $categoryNames = Category::whereIn('id', $categoryIds)->pluck('name')->all();

            $query->where(function ($query) use ($categoryIds, $categoryNames) {
                $query->whereHas('categories', fn ($categories) => $categories->whereIn('categories.id', $categoryIds))
                    ->orWhereIn('category_id', $categoryIds)
                    ->orWhereIn('category', $categoryNames);
            });
        } elseif ($request->has('category')) {
            $query->where('category', $request->input('category'));
        }

        if ($request->has('search')) {
            $query->where('name', 'LIKE', '%'.$request->input('search').'%');
        }

        $query->orderBy(
            $request->input('sort', 'created_at'),
            $request->input('order', 'desc'),
        );

        if ($request->has('limit')) {
            return ['data' => $query->limit($request->input('limit'))->get()];
        }

        return $query->paginate($request->input('per_page', 15))->toArray();
    }

    public function show(string $slug): Product
    {
        $product = Product::with(['media', 'categories', 'options.values'])
            ->where('slug', $slug)
            ->firstOrFail();
        $product->setRelation(
            'variants',
            $product->activeVariants()->with('optionValues')->get(),
        );

        return $product;
    }
}
