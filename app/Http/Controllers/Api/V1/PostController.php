<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Editorial\Application\EditorialQuery;
use App\Modules\Editorial\Application\PostManager;
use App\Support\Tenancy\CurrentStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PostController extends Controller
{
    public function __construct(
        private readonly EditorialQuery $editorial,
        private readonly PostManager $posts,
    ) {}

    /**
     * Public listing. Only returns published posts.
     */
    public function index(Request $request): JsonResponse
    {
        $filters = $request->only(['category', 'search', 'sort', 'limit', 'per_page']);
        $filters['featured'] = $request->boolean('featured');
        $result = $this->editorial->publicPosts($filters);

        return response()->json($request->filled('limit') ? ['data' => $result] : $result);
    }

    public function show(string $slug): JsonResponse
    {
        return response()->json($this->editorial->post($slug));
    }

    /**
     * Admin listing. Returns all posts regardless of publication status.
     */
    public function adminIndex(Request $request): JsonResponse
    {
        $filters = $request->only(['category_id', 'search', 'sort', 'order', 'per_page']);
        if ($request->has('is_published')) {
            $filters['is_published'] = $request->boolean('is_published');
        }

        return response()->json($this->editorial->adminPosts($filters));
    }

    public function adminShow(int $id): JsonResponse
    {
        return response()->json(['data' => $this->editorial->adminPost($id)]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $this->validatePost($request);

        unset($validated['cover_image']);
        $post = $this->posts->create($validated, $request->file('cover_image'));

        return response()->json([
            'message' => 'Post created successfully',
            'data' => $post,
        ], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $validated = $this->validatePost($request, true);
        unset($validated['cover_image']);
        $post = $this->posts->update($id, $validated, $request->file('cover_image'));

        return response()->json([
            'message' => 'Post updated successfully',
            'data' => $post,
        ]);
    }

    public function destroy(int $id): JsonResponse
    {
        $this->posts->delete($id);

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
            'cover_alt_text' => 'nullable|string|max:255',
            'author_name' => 'nullable|string|max:255',
            'category_id' => [
                'nullable',
                Rule::exists('post_categories', 'id')->where('store_id', app(CurrentStore::class)->id()),
            ],
            'is_published' => 'nullable|boolean',
            'is_featured' => 'nullable|boolean',
            'published_at' => 'nullable|date',
            'seo_title' => 'nullable|string|max:255',
            'seo_description' => 'nullable|string|max:500',
            'og_image_url' => 'nullable|url|max:500',
            'noindex' => 'nullable|boolean',
        ]);
    }
}
