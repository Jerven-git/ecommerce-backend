<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Category;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CategoryController extends Controller
{
    public function index(): JsonResponse
    {
        $categories = Category::whereNull('parent_id')
            ->with('childrenRecursive')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return response()->json(['data' => $categories]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name'      => 'required|string|max:255',
            'parent_id' => 'nullable|exists:categories,id',
            'sort_order' => 'nullable|integer|min:0',
        ]);

        $category = Category::create($validated);
        $category->load('childrenRecursive');

        return response()->json(['data' => $category], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $category = Category::findOrFail($id);

        $validated = $request->validate([
            'name'       => 'sometimes|required|string|max:255',
            'parent_id'  => 'nullable|exists:categories,id',
            'sort_order' => 'nullable|integer|min:0',
        ]);

        // Prevent setting parent_id to self or to a descendant (circular reference)
        if (($validated['parent_id'] ?? null) !== null) {
            $parentId = (int) $validated['parent_id'];
            if ($parentId === $id) {
                return response()->json(['message' => 'A category cannot be its own parent.'], 422);
            }
            $category->load('childrenRecursive');
            if (in_array($parentId, $category->allDescendantIds())) {
                return response()->json(['message' => 'Cannot set a descendant as the parent.'], 422);
            }
        }

        $category->update($validated);
        $category->load('childrenRecursive');

        return response()->json(['data' => $category]);
    }

    public function reorder(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'ids'   => 'required|array|min:1',
            'ids.*' => 'integer|exists:categories,id',
        ]);

        foreach ($validated['ids'] as $order => $id) {
            Category::where('id', $id)->update(['sort_order' => $order]);
        }

        return response()->json(['message' => 'Reordered.']);
    }

    public function destroy(int $id): JsonResponse
    {
        $category = Category::findOrFail($id);
        $category->delete();

        return response()->json(['message' => 'Category deleted.']);
    }
}
