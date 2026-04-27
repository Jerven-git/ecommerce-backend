<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Post;
use App\Models\PostCategory;
use App\Modules\Media\MediaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class PostController extends Controller
{
    public function __construct(private MediaService $mediaService) {}

    /**
     * Public listing. Only returns published posts.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Post::with('category')->published();

        if ($request->filled('category')) {
            $category = PostCategory::where('slug', $request->category)->first();
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
                  ->orWhere('excerpt', 'LIKE', "%{$s}%");
            });
        }

        if ($request->boolean('featured')) {
            $query->featured();
        }

        $sort = $request->input('sort', 'newest');
        $query->orderBy('published_at', $sort === 'oldest' ? 'asc' : 'desc');

        if ($request->filled('limit')) {
            return response()->json(['data' => $query->limit((int) $request->limit)->get()]);
        }

        $posts = $query->paginate($request->input('per_page', 12));
        return response()->json($posts);
    }

    public function show(string $slug): JsonResponse
    {
        $post = Post::with(['category', 'media'])
            ->published()
            ->where('slug', $slug)
            ->firstOrFail();

        $related = Post::with('category')
            ->published()
            ->where('id', '!=', $post->id)
            ->when($post->category_id, fn ($q) => $q->where('category_id', $post->category_id))
            ->orderByDesc('published_at')
            ->limit(3)
            ->get();

        return response()->json([
            'data' => $post,
            'related' => $related,
        ]);
    }

    /**
     * Admin listing. Returns all posts regardless of publication status.
     */
    public function adminIndex(Request $request): JsonResponse
    {
        $query = Post::with('category');

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
                  ->orWhere('excerpt', 'LIKE', "%{$s}%");
            });
        }

        $sort = $request->input('sort', 'created_at');
        $order = $request->input('order', 'desc');
        $query->orderBy($sort, $order);

        $posts = $query->paginate($request->input('per_page', 15));
        return response()->json($posts);
    }

    public function adminShow(int $id): JsonResponse
    {
        $post = Post::with(['category', 'media'])->findOrFail($id);
        return response()->json(['data' => $post]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $this->validatePost($request);

        $validated['slug'] = Post::generateUniqueSlug($validated['title']);
        $this->resolvePublishedAt($validated);

        $post = Post::create($validated);

        if ($request->hasFile('cover_image')) {
            $media = $this->mediaService->upload($request->file('cover_image'), $post, 'cover', 'posts');
            $post->update(['cover_image_url' => Storage::disk('public')->url($media->path)]);
        }

        return response()->json([
            'message' => 'Post created successfully',
            'data' => $post->fresh()->load(['category', 'media']),
        ], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $post = Post::findOrFail($id);

        $validated = $this->validatePost($request, true);

        if (isset($validated['title']) && $validated['title'] !== $post->title) {
            $validated['slug'] = Post::generateUniqueSlug($validated['title'], $post->id);
        }

        $this->resolvePublishedAt($validated, $post);

        $post->update($validated);

        if ($request->hasFile('cover_image')) {
            $media = $this->mediaService->upload($request->file('cover_image'), $post, 'cover', 'posts');
            $post->update(['cover_image_url' => Storage::disk('public')->url($media->path)]);
        }

        return response()->json([
            'message' => 'Post updated successfully',
            'data' => $post->fresh()->load(['category', 'media']),
        ]);
    }

    public function destroy(int $id): JsonResponse
    {
        $post = Post::findOrFail($id);
        $post->delete();

        return response()->json(['message' => 'Post deleted successfully']);
    }

    private function validatePost(Request $request, bool $partial = false): array
    {
        $required = $partial ? 'sometimes|required' : 'required';

        return $request->validate([
            'title' => "$required|string|max:255",
            'excerpt' => 'nullable|string|max:500',
            'body' => 'nullable|string',
            'cover_image' => 'nullable|image|max:5120',
            'cover_image_url' => 'nullable|string|max:500',
            'author_name' => 'nullable|string|max:255',
            'category_id' => 'nullable|exists:post_categories,id',
            'is_published' => 'nullable|boolean',
            'is_featured' => 'nullable|boolean',
            'published_at' => 'nullable|date',
            'seo_title' => 'nullable|string|max:255',
            'seo_description' => 'nullable|string|max:500',
        ]);
    }

    /**
     * Ensure published_at is set when flipping to published, preserved otherwise.
     */
    private function resolvePublishedAt(array &$validated, ?Post $existing = null): void
    {
        unset($validated['cover_image']);

        $willPublish = array_key_exists('is_published', $validated)
            ? (bool) $validated['is_published']
            : (bool) ($existing->is_published ?? false);

        if ($willPublish && empty($validated['published_at']) && !($existing && $existing->published_at)) {
            $validated['published_at'] = now();
        }
    }
}
