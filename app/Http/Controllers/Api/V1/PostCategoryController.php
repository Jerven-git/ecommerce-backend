<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\PostCategory;
use App\Modules\Editorial\Application\TaxonomyManager;
use App\Support\Tenancy\CurrentStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PostCategoryController extends Controller
{
    public function __construct(private readonly TaxonomyManager $categories) {}

    public function index(Request $request): JsonResponse
    {
        $paginated = $request->filled('page') || $request->filled('per_page');
        $result = $this->categories->list(PostCategory::class, 'posts', [
            'search' => $request->input('search'),
            'paginate' => $paginated,
            'per_page' => $request->input('per_page', 10),
        ]);

        return response()->json($paginated ? $result : ['data' => $result]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $this->validateCategory($request);

        unset($validated['image']);
        $category = $this->categories->create(
            PostCategory::class, $validated, $request->file('image'), 'post-categories'
        );

        return response()->json(['data' => $category->fresh()], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $validated = $this->validateCategory($request, partial: true);
        unset($validated['image']);
        $category = $this->categories->update(
            PostCategory::class, $id, $validated, $request->file('image'), 'post-categories'
        );

        return response()->json(['data' => $category]);
    }

    public function deleteImage(int $id): JsonResponse
    {
        return response()->json(['data' => $this->categories->deleteImage(PostCategory::class, $id)]);
    }

    public function reorder(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => [
                'integer',
                Rule::exists('post_categories', 'id')->where('store_id', app(CurrentStore::class)->id()),
            ],
        ]);
        $this->categories->reorder(PostCategory::class, $validated['ids']);

        return response()->json(['message' => 'Reordered.']);
    }

    public function destroy(int $id): JsonResponse
    {
        $this->categories->delete(PostCategory::class, $id);

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
