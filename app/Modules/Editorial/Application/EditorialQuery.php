<?php

namespace App\Modules\Editorial\Application;

use App\Models\Post;
use App\Models\PostCategory;
use App\Models\Service;
use App\Models\ServiceCategory;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

class EditorialQuery
{
    public function publicPosts(array $filters): Collection|LengthAwarePaginator
    {
        $query = Post::with('category')->published();
        $this->applyCategory($query, PostCategory::class, $filters['category'] ?? null);
        $this->applySearch($query, $filters['search'] ?? null, 'excerpt');
        $this->applyFeatured($query, $filters['featured'] ?? false);
        $query->orderBy('published_at', ($filters['sort'] ?? 'newest') === 'oldest' ? 'asc' : 'desc');

        return $this->limitOrPaginate($query, $filters, 12);
    }

    public function publicServices(array $filters): Collection|LengthAwarePaginator
    {
        $query = Service::with('category')->published();
        $this->applyCategory($query, ServiceCategory::class, $filters['category'] ?? null);
        $this->applySearch($query, $filters['search'] ?? null, 'description');
        $this->applyFeatured($query, $filters['featured'] ?? false);

        match ($filters['sort'] ?? 'default') {
            'newest' => $query->orderByDesc('published_at'),
            'oldest' => $query->orderBy('published_at'),
            default => $query->orderBy('sort_order')->orderByDesc('published_at'),
        };

        return $this->limitOrPaginate($query, $filters, 12);
    }

    public function post(string $slug): array
    {
        $post = Post::with(['category', 'media'])->published()->where('slug', $slug)->firstOrFail();

        return [
            'data' => $post,
            'related' => Post::with('category')->published()
                ->whereKeyNot($post->id)
                ->when($post->category_id, fn ($query) => $query->where('category_id', $post->category_id))
                ->orderByDesc('published_at')->limit(3)->get(),
        ];
    }

    public function service(string $slug): array
    {
        $service = Service::with(['category', 'media'])->published()->where('slug', $slug)->firstOrFail();

        return [
            'data' => $service,
            'related' => Service::with('category')->published()
                ->whereKeyNot($service->id)
                ->when($service->category_id, fn ($query) => $query->where('category_id', $service->category_id))
                ->orderBy('sort_order')->orderByDesc('published_at')->limit(3)->get(),
        ];
    }

    public function adminPosts(array $filters): LengthAwarePaginator
    {
        return $this->admin(Post::query()->with('category'), $filters, 'excerpt');
    }

    public function adminServices(array $filters): LengthAwarePaginator
    {
        return $this->admin(Service::query()->with('category'), $filters, 'description');
    }

    public function adminPost(int $id): Post
    {
        return Post::with(['category', 'media'])->findOrFail($id);
    }

    public function adminService(int $id): Service
    {
        return Service::with(['category', 'media'])->findOrFail($id);
    }

    private function admin($query, array $filters, string $secondarySearch): LengthAwarePaginator
    {
        if (array_key_exists('is_published', $filters)) {
            $query->where('is_published', $filters['is_published']);
        }
        if (! empty($filters['category_id'])) {
            $query->where('category_id', (int) $filters['category_id']);
        }
        $this->applySearch($query, $filters['search'] ?? null, $secondarySearch);

        return $query->orderBy($filters['sort'] ?? 'created_at', $filters['order'] ?? 'desc')
            ->paginate($filters['per_page'] ?? 15);
    }

    private function applyCategory($query, string $categoryClass, ?string $slug): void
    {
        if (! filled($slug)) {
            return;
        }

        $category = $categoryClass::where('slug', $slug)->first();
        $category ? $query->where('category_id', $category->id) : $query->whereRaw('1 = 0');
    }

    private function applySearch($query, ?string $search, string $secondary): void
    {
        if (filled($search)) {
            $query->where(fn ($query) => $query->where('title', 'LIKE', "%{$search}%")
                ->orWhere($secondary, 'LIKE', "%{$search}%"));
        }
    }

    private function applyFeatured($query, bool $featured): void
    {
        if ($featured) {
            $query->featured();
        }
    }

    private function limitOrPaginate($query, array $filters, int $perPage): Collection|LengthAwarePaginator
    {
        return filled($filters['limit'] ?? null)
            ? $query->limit((int) $filters['limit'])->get()
            : $query->paginate($filters['per_page'] ?? $perPage);
    }
}
