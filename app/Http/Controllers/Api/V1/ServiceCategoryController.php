<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ServiceCategory;
use App\Modules\Media\MediaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ServiceCategoryController extends Controller
{
    public function __construct(private MediaService $mediaService) {}

    public function index(): JsonResponse
    {
        $categories = ServiceCategory::orderBy('sort_order')
            ->orderBy('name')
            ->withCount(['services' => fn ($q) => $q->published()])
            ->get();

        return response()->json(['data' => $categories]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $this->validateCategory($request);

        $file = $request->file('image');
        unset($validated['image']);

        $validated['slug'] = ServiceCategory::generateUniqueSlug($validated['name']);
        $category = ServiceCategory::create($validated);

        if ($file) {
            $media = $this->mediaService->upload($file, $category, 'image', 'service-categories');
            $category->update(['image_url' => Storage::disk('public')->url($media->path)]);
        }

        return response()->json(['data' => $category->fresh()], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $category = ServiceCategory::findOrFail($id);

        $validated = $this->validateCategory($request, partial: true);

        $file = $request->file('image');
        unset($validated['image']);

        if (isset($validated['name']) && $validated['name'] !== $category->name) {
            $validated['slug'] = ServiceCategory::generateUniqueSlug($validated['name'], $category->id);
        }

        if ($file) {
            $media = $this->mediaService->upload($file, $category, 'image', 'service-categories');
            $validated['image_url'] = Storage::disk('public')->url($media->path);
        }

        $category->update($validated);

        return response()->json(['data' => $category->fresh()]);
    }

    public function deleteImage(int $id): JsonResponse
    {
        $category = ServiceCategory::findOrFail($id);
        $media = $category->media()->where('collection', 'image')->first();

        if ($media) {
            Storage::disk('public')->delete($media->path);
            $media->delete();
        }
        $category->update(['image_url' => null]);

        return response()->json(['data' => $category->fresh()]);
    }

    public function reorder(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => 'integer|exists:service_categories,id',
        ]);

        foreach ($validated['ids'] as $order => $id) {
            ServiceCategory::where('id', $id)->update(['sort_order' => $order]);
        }

        return response()->json(['message' => 'Reordered.']);
    }

    public function destroy(int $id): JsonResponse
    {
        $category = ServiceCategory::findOrFail($id);

        foreach ($category->media as $media) {
            Storage::disk('public')->delete($media->path);
            $media->delete();
        }

        $category->delete();

        return response()->json(['message' => 'Category deleted.']);
    }

    private function validateCategory(Request $request, bool $partial = false): array
    {
        $required = $partial ? 'sometimes|required' : 'required';

        return $request->validate([
            'name' => "$required|string|max:255",
            'gradient_from' => 'nullable|string|regex:/^#[0-9A-Fa-f]{6}$/',
            'gradient_to' => 'nullable|string|regex:/^#[0-9A-Fa-f]{6}$/',
            'image' => 'nullable|image|max:5120',
            'image_url' => 'nullable|string|max:500',
            'overlay_opacity' => 'nullable|integer|min:0|max:100',
            'sort_order' => 'nullable|integer|min:0',
        ]);
    }
}
