<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\ReorderCategoriesRequest;
use App\Http\Requests\Catalog\StoreCategoryRequest;
use App\Http\Requests\Catalog\UpdateCategoryRequest;
use App\Modules\Catalog\Application\CatalogOperationException;
use App\Modules\Catalog\Application\CategoryManager;
use App\Modules\Catalog\Application\CategoryQuery;
use Illuminate\Http\JsonResponse;

class CategoryController extends Controller
{
    public function index(CategoryQuery $categories): JsonResponse
    {
        return response()->json(['data' => $categories->tree()]);
    }

    public function store(StoreCategoryRequest $request, CategoryManager $categories): JsonResponse
    {
        return response()->json([
            'data' => $categories->create($request->validated(), $request->file('image')),
        ], 201);
    }

    public function update(UpdateCategoryRequest $request, int $id, CategoryManager $categories): JsonResponse
    {
        try {
            return response()->json([
                'data' => $categories->update($id, $request->validated(), $request->file('image')),
            ]);
        } catch (CatalogOperationException $exception) {
            return response()->json(['message' => $exception->getMessage()], $exception->status);
        }
    }

    public function deleteImage(int $id, CategoryManager $categories): JsonResponse
    {
        return response()->json(['data' => $categories->deleteImage($id)]);
    }

    public function reorder(ReorderCategoriesRequest $request, CategoryManager $categories): JsonResponse
    {
        $categories->reorder($request->validated('ids'));

        return response()->json(['message' => 'Reordered.']);
    }

    public function destroy(int $id, CategoryManager $categories): JsonResponse
    {
        $categories->delete($id);

        return response()->json(['message' => 'Category deleted.']);
    }
}
