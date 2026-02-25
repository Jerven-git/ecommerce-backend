<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Category;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CategoryController extends Controller
{
    public function index(): JsonResponse
    {
        $categories = Category::whereNull('parent_id')
            ->with('children')
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

        // Prevent nesting beyond 2 levels
        if ($validated['parent_id'] ?? null) {
            $parent = Category::find($validated['parent_id']);
            if ($parent && $parent->parent_id !== null) {
                return response()->json(['message' => 'Subcategories cannot have their own subcategories.'], 422);
            }
        }

        $category = Category::create($validated);
        $category->load('children');

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

        // Prevent nesting beyond 2 levels
        if (isset($validated['parent_id']) && $validated['parent_id'] !== null) {
            $parent = Category::find($validated['parent_id']);
            if ($parent && $parent->parent_id !== null) {
                return response()->json(['message' => 'Subcategories cannot have their own subcategories.'], 422);
            }
        }

        // Prevent setting parent_id to self
        if (($validated['parent_id'] ?? null) == $id) {
            return response()->json(['message' => 'A category cannot be its own parent.'], 422);
        }

        $category->update($validated);
        $category->load('children');

        return response()->json(['data' => $category]);
    }

    public function destroy(int $id): JsonResponse
    {
        $category = Category::findOrFail($id);
        $category->delete();

        return response()->json(['message' => 'Category deleted.']);
    }
}
