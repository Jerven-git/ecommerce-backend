<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ServiceCategory;
use App\Modules\Editorial\Application\TaxonomyManager;
use App\Support\Tenancy\CurrentStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ServiceCategoryController extends Controller
{
    public function __construct(private readonly TaxonomyManager $categories) {}

    public function index(Request $request): JsonResponse
    {
        $paginated = $request->filled('page') || $request->filled('per_page');
        $result = $this->categories->list(ServiceCategory::class, 'services', [
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
            ServiceCategory::class, $validated, $request->file('image'), 'service-categories'
        );

        return response()->json(['data' => $category->fresh()], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $validated = $this->validateCategory($request, partial: true);
        unset($validated['image']);
        $category = $this->categories->update(
            ServiceCategory::class, $id, $validated, $request->file('image'), 'service-categories'
        );

        return response()->json(['data' => $category]);
    }

    public function deleteImage(int $id): JsonResponse
    {
        return response()->json(['data' => $this->categories->deleteImage(ServiceCategory::class, $id)]);
    }

    public function reorder(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => [
                'integer',
                Rule::exists('service_categories', 'id')->where('store_id', app(CurrentStore::class)->id()),
            ],
        ]);
        $this->categories->reorder(ServiceCategory::class, $validated['ids']);

        return response()->json(['message' => 'Reordered.']);
    }

    public function destroy(int $id): JsonResponse
    {
        $this->categories->delete(ServiceCategory::class, $id);

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
