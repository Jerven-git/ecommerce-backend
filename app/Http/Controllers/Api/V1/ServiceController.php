<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Editorial\Application\EditorialQuery;
use App\Modules\Editorial\Application\ServiceManager;
use App\Support\Tenancy\CurrentStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ServiceController extends Controller
{
    public function __construct(
        private readonly EditorialQuery $editorial,
        private readonly ServiceManager $services,
    ) {}

    /**
     * Public listing. Only returns published services.
     */
    public function index(Request $request): JsonResponse
    {
        $filters = $request->only(['category', 'search', 'sort', 'limit', 'per_page']);
        $filters['featured'] = $request->boolean('featured');
        $result = $this->editorial->publicServices($filters);

        return response()->json($request->filled('limit') ? ['data' => $result] : $result);
    }

    public function show(string $slug): JsonResponse
    {
        return response()->json($this->editorial->service($slug));
    }

    /**
     * Admin listing. Returns all services regardless of publication status.
     */
    public function adminIndex(Request $request): JsonResponse
    {
        $filters = $request->only(['category_id', 'search', 'sort', 'order', 'per_page']);
        if ($request->has('is_published')) {
            $filters['is_published'] = $request->boolean('is_published');
        }

        return response()->json($this->editorial->adminServices($filters));
    }

    public function adminShow(int $id): JsonResponse
    {
        return response()->json(['data' => $this->editorial->adminService($id)]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $this->validateService($request);

        unset($validated['cover_image']);
        $service = $this->services->create($validated, $request->file('cover_image'));

        return response()->json([
            'message' => 'Service created successfully',
            'data' => $service,
        ], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $validated = $this->validateService($request, true);
        unset($validated['cover_image']);
        $service = $this->services->update($id, $validated, $request->file('cover_image'));

        return response()->json([
            'message' => 'Service updated successfully',
            'data' => $service,
        ]);
    }

    public function destroy(int $id): JsonResponse
    {
        $this->services->delete($id);

        return response()->json(['message' => 'Service deleted successfully']);
    }

    public function reorder(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => [
                'integer',
                Rule::exists('services', 'id')->where('store_id', app(CurrentStore::class)->id()),
            ],
        ]);

        $this->services->reorder($validated['ids']);

        return response()->json(['message' => 'Reordered.']);
    }

    private function validateService(Request $request, bool $partial = false): array
    {
        $required = $partial ? 'sometimes|required' : 'required';

        return $request->validate([
            'title' => "$required|string|max:255",
            'eyebrow' => 'nullable|string|max:100',
            'description' => 'nullable|string|max:500',
            'body' => 'nullable|string',
            'cover_image' => 'nullable|image|max:5120',
            'cover_image_url' => 'nullable|string|max:500',
            'cover_alt_text' => 'nullable|string|max:255',
            'category_id' => [
                'nullable',
                Rule::exists('service_categories', 'id')->where('store_id', app(CurrentStore::class)->id()),
            ],
            'cta_label' => 'nullable|string|max:100',
            'cta_link' => 'nullable|string|max:500',
            'is_published' => 'nullable|boolean',
            'is_featured' => 'nullable|boolean',
            'published_at' => 'nullable|date',
            'seo_title' => 'nullable|string|max:255',
            'seo_description' => 'nullable|string|max:500',
            'og_image_url' => 'nullable|url|max:500',
            'noindex' => 'nullable|boolean',
            'sort_order' => 'nullable|integer|min:0',
        ]);
    }
}
