<?php

namespace App\Modules\Catalog\Application;

use App\Models\Category;
use App\Modules\Media\MediaService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class CategoryManager
{
    public function __construct(private readonly MediaService $media) {}

    /** @param array<string, mixed> $data */
    public function create(array $data, ?UploadedFile $image): Category
    {
        unset($data['image']);
        $data['slug'] = Category::generateUniqueSlug($data['name']);
        $category = Category::create($data);

        if ($image) {
            $media = $this->media->upload($image, $category, 'image', 'categories');
            $category->update(['image_url' => Storage::disk('public')->url($media->path)]);
        }

        return $category->fresh('childrenRecursive');
    }

    /** @param array<string, mixed> $data */
    public function update(int $id, array $data, ?UploadedFile $image): Category
    {
        $category = Category::findOrFail($id);
        unset($data['image']);

        if (($data['parent_id'] ?? null) !== null) {
            $parentId = (int) $data['parent_id'];
            if ($parentId === $id) {
                throw new CatalogOperationException('A category cannot be its own parent.');
            }

            $category->load('childrenRecursive');
            if (in_array($parentId, $category->allDescendantIds(), true)) {
                throw new CatalogOperationException('Cannot set a descendant as the parent.');
            }
        }

        if (isset($data['name']) && $data['name'] !== $category->name) {
            $data['slug'] = Category::generateUniqueSlug($data['name'], $category->id);
        }

        if ($image) {
            $media = $this->media->upload($image, $category, 'image', 'categories');
            $data['image_url'] = Storage::disk('public')->url($media->path);
        }

        $category->update($data);

        return $category->fresh('childrenRecursive');
    }

    public function deleteImage(int $id): Category
    {
        $category = Category::findOrFail($id);
        $media = $category->media()->where('collection', 'image')->first();

        if ($media) {
            Storage::disk('public')->delete($media->path);
            $media->delete();
        }

        $category->update(['image_url' => null]);

        return $category->fresh('childrenRecursive');
    }

    /** @param list<int> $ids */
    public function reorder(array $ids): void
    {
        foreach ($ids as $sortOrder => $id) {
            Category::whereKey($id)->update(['sort_order' => $sortOrder]);
        }
    }

    public function delete(int $id): void
    {
        $category = Category::findOrFail($id);

        foreach ($category->media as $media) {
            Storage::disk('public')->delete($media->path);
            $media->delete();
        }

        $category->delete();
    }
}
