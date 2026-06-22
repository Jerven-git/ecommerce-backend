<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Modules\Media\MediaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class CategoryController extends Controller
{
    public function __construct(private MediaService $mediaService) {}

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
        $validated = $this->validateCategory($request);

        $file = $request->file('image');
        unset($validated['image']);

        $validated['slug'] = Category::generateUniqueSlug($validated['name']);
        $category = Category::create($validated);

        if ($file) {
            $media = $this->mediaService->upload($file, $category, 'image', 'categories');
            $category->update(['image_url' => Storage::disk('public')->url($media->path)]);
        }

        return response()->json(['data' => $category->fresh('childrenRecursive')], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $category = Category::findOrFail($id);

        $validated = $this->validateCategory($request, partial: true);

        $file = $request->file('image');
        unset($validated['image']);

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

        if (isset($validated['name']) && $validated['name'] !== $category->name) {
            $validated['slug'] = Category::generateUniqueSlug($validated['name'], $category->id);
        }

        if ($file) {
            $media = $this->mediaService->upload($file, $category, 'image', 'categories');
            $validated['image_url'] = Storage::disk('public')->url($media->path);
        }

        $category->update($validated);

        return response()->json(['data' => $category->fresh('childrenRecursive')]);
    }

    public function deleteImage(int $id): JsonResponse
    {
        $category = Category::findOrFail($id);
        $media = $category->media()->where('collection', 'image')->first();

        if ($media) {
            Storage::disk('public')->delete($media->path);
            $media->delete();
        }
        $category->update(['image_url' => null]);

        return response()->json(['data' => $category->fresh('childrenRecursive')]);
    }

    public function reorder(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'ids' => 'required|array|min:1',
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

        foreach ($category->media as $media) {
            Storage::disk('public')->delete($media->path);
            $media->delete();
        }

        $category->delete();

        return response()->json(['message' => 'Category deleted.']);
    }

    /**
     * @return array<string, mixed>
     */
    private function validateCategory(Request $request, bool $partial = false): array
    {
        $required = $partial ? 'sometimes|required' : 'required';

        return $request->validate([
            'name' => "$required|string|max:255",
            'parent_id' => 'nullable|exists:categories,id',
            'sort_order' => 'nullable|integer|min:0',
            'image' => 'nullable|image|max:5120',
            'image_url' => 'nullable|string|max:500',
            'overlay_opacity' => 'nullable|integer|min:0|max:100',
        ]);
    }
}
