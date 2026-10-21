<?php

namespace App\Modules\Editorial\Application;

use App\Modules\Media\MediaService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class TaxonomyManager
{
    public function __construct(private readonly MediaService $media) {}

    public function list(string $modelClass, string $relation, array $filters): Collection|LengthAwarePaginator
    {
        $query = $modelClass::orderBy('sort_order')->orderBy('name')
            ->withCount([$relation => fn ($query) => $query->published()]);

        if (filled($filters['search'] ?? null)) {
            $search = $filters['search'];
            $query->where(fn ($query) => $query->where('name', 'LIKE', "%{$search}%")
                ->orWhere('slug', 'LIKE', "%{$search}%"));
        }

        if (($filters['paginate'] ?? false) === true) {
            return $query->paginate(max(1, min(100, (int) ($filters['per_page'] ?? 10))));
        }

        return $query->get();
    }

    public function create(string $modelClass, array $attributes, ?UploadedFile $image, string $directory): Model
    {
        $attributes['slug'] = $modelClass::generateUniqueSlug($attributes['name']);
        $category = $modelClass::create($attributes);
        $this->upload($category, $image, $directory);

        return $category->fresh();
    }

    public function update(string $modelClass, int $id, array $attributes, ?UploadedFile $image, string $directory): Model
    {
        $category = $modelClass::findOrFail($id);
        if (isset($attributes['name']) && $attributes['name'] !== $category->name) {
            $attributes['slug'] = $modelClass::generateUniqueSlug($attributes['name'], $category->id);
        }
        $category->update($attributes);
        $this->upload($category, $image, $directory);

        return $category->fresh();
    }

    public function deleteImage(string $modelClass, int $id): Model
    {
        $category = $modelClass::findOrFail($id);
        $media = $category->media()->where('collection', 'image')->first();
        if ($media) {
            Storage::disk('public')->delete($media->path);
            $media->delete();
        }
        $category->update(['image_url' => null]);

        return $category->fresh();
    }

    public function reorder(string $modelClass, array $ids): void
    {
        foreach ($ids as $order => $id) {
            $modelClass::whereKey($id)->update(['sort_order' => $order]);
        }
    }

    public function delete(string $modelClass, int $id): void
    {
        $category = $modelClass::findOrFail($id);
        foreach ($category->media as $media) {
            Storage::disk('public')->delete($media->path);
            $media->delete();
        }
        $category->delete();
    }

    private function upload(Model $category, ?UploadedFile $image, string $directory): void
    {
        if ($image) {
            $media = $this->media->upload($image, $category, 'image', $directory);
            $category->update(['image_url' => Storage::disk('public')->url($media->path)]);
        }
    }
}
