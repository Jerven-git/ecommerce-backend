<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Modules\Media\MediaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ServiceController extends Controller
{
    public function __construct(private MediaService $mediaService) {}

    /**
     * Public listing. Only returns published services.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Service::with('category')->published();

        if ($request->filled('category')) {
            $category = ServiceCategory::where('slug', $request->category)->first();
            if ($category) {
                $query->where('category_id', $category->id);
            } else {
                $query->whereRaw('1 = 0');
            }
        }

        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(function ($q) use ($s) {
                $q->where('title', 'LIKE', "%{$s}%")
                    ->orWhere('description', 'LIKE', "%{$s}%");
            });
        }

        if ($request->boolean('featured')) {
            $query->featured();
        }

        $sort = $request->input('sort', 'default');
        if ($sort === 'newest') {
            $query->orderByDesc('published_at');
        } elseif ($sort === 'oldest') {
            $query->orderBy('published_at');
        } else {
            // Default: respect per-category ordering, then creation order
            $query->orderBy('sort_order')->orderByDesc('published_at');
        }

        if ($request->filled('limit')) {
            return response()->json(['data' => $query->limit((int) $request->limit)->get()]);
        }

        $services = $query->paginate($request->input('per_page', 12));

        return response()->json($services);
    }

    public function show(string $slug): JsonResponse
    {
        $service = Service::with(['category', 'media'])
            ->published()
            ->where('slug', $slug)
            ->firstOrFail();

        $related = Service::with('category')
            ->published()
            ->where('id', '!=', $service->id)
            ->when($service->category_id, fn ($q) => $q->where('category_id', $service->category_id))
            ->orderBy('sort_order')
            ->orderByDesc('published_at')
            ->limit(3)
            ->get();

        return response()->json([
            'data' => $service,
            'related' => $related,
        ]);
    }

    /**
     * Admin listing. Returns all services regardless of publication status.
     */
    public function adminIndex(Request $request): JsonResponse
    {
        $query = Service::with('category');

        if ($request->has('is_published')) {
            $query->where('is_published', $request->boolean('is_published'));
        }

        if ($request->filled('category_id')) {
            $query->where('category_id', (int) $request->category_id);
        }

        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(function ($q) use ($s) {
                $q->where('title', 'LIKE', "%{$s}%")
                    ->orWhere('description', 'LIKE', "%{$s}%");
            });
        }

        $sort = $request->input('sort', 'created_at');
        $order = $request->input('order', 'desc');
        $query->orderBy($sort, $order);

        $services = $query->paginate($request->input('per_page', 15));

        return response()->json($services);
    }

    public function adminShow(int $id): JsonResponse
    {
        $service = Service::with(['category', 'media'])->findOrFail($id);

        return response()->json(['data' => $service]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $this->validateService($request);

        $validated['slug'] = Service::generateUniqueSlug($validated['title']);
        $this->resolvePublishedAt($validated);

        $service = Service::create($validated);

        if ($request->hasFile('cover_image')) {
            $media = $this->mediaService->upload($request->file('cover_image'), $service, 'cover', 'services');
            $service->update(['cover_image_url' => Storage::disk('public')->url($media->path)]);
        }

        return response()->json([
            'message' => 'Service created successfully',
            'data' => $service->fresh()->load(['category', 'media']),
        ], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $service = Service::findOrFail($id);

        $validated = $this->validateService($request, true);

        if (isset($validated['title']) && $validated['title'] !== $service->title) {
            $validated['slug'] = Service::generateUniqueSlug($validated['title'], $service->id);
        }

        $this->resolvePublishedAt($validated, $service);

        $service->update($validated);

        if ($request->hasFile('cover_image')) {
            $media = $this->mediaService->upload($request->file('cover_image'), $service, 'cover', 'services');
            $service->update(['cover_image_url' => Storage::disk('public')->url($media->path)]);
        }

        return response()->json([
            'message' => 'Service updated successfully',
            'data' => $service->fresh()->load(['category', 'media']),
        ]);
    }

    public function destroy(int $id): JsonResponse
    {
        $service = Service::findOrFail($id);

        foreach ($service->media as $media) {
            Storage::disk('public')->delete($media->path);
            $media->delete();
        }

        $service->delete();

        return response()->json(['message' => 'Service deleted successfully']);
    }

    public function reorder(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => 'integer|exists:services,id',
        ]);

        foreach ($validated['ids'] as $order => $id) {
            Service::where('id', $id)->update(['sort_order' => $order]);
        }

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
            'category_id' => 'nullable|exists:service_categories,id',
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

    /**
     * Ensure published_at is set when flipping to published, preserved otherwise.
     */
    private function resolvePublishedAt(array &$validated, ?Service $existing = null): void
    {
        unset($validated['cover_image']);

        $willPublish = array_key_exists('is_published', $validated)
            ? (bool) $validated['is_published']
            : (bool) ($existing->is_published ?? false);

        if ($willPublish && empty($validated['published_at']) && ! ($existing && $existing->published_at)) {
            $validated['published_at'] = now();
        }
    }
}
