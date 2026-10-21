<?php

namespace App\Modules\Catalog\Application;

use App\Models\Product;
use App\Modules\Media\MediaService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class ProductManager
{
    public function __construct(private readonly MediaService $media) {}

    /** @param array<string, mixed> $data */
    public function create(array $data, ?UploadedFile $image, ?UploadedFile $hoverImage): Product
    {
        $categoryIds = $data['category_ids'] ?? [];
        unset($data['image'], $data['hover_image'], $data['category_ids']);

        $data['slug'] = Product::generateUniqueSlug($data['name']);
        $product = Product::create($data);
        if (! empty($categoryIds)) {
            $product->categories()->sync($categoryIds);
        }

        $this->storePrimaryImages($product, $image, $hoverImage);

        return $product->fresh()->load('categories');
    }

    /** @param array<string, mixed> $data */
    public function update(int|string $id, array $data, ?UploadedFile $image, ?UploadedFile $hoverImage): Product
    {
        $product = Product::findOrFail($id);
        $categoryIds = $data['category_ids'] ?? null;
        unset($data['image'], $data['hover_image'], $data['category_ids']);

        if (isset($data['name']) && $data['name'] !== $product->name) {
            $data['slug'] = Product::generateUniqueSlug($data['name'], $product->id);
        }

        $product->update($data);
        if ($categoryIds !== null) {
            $product->categories()->sync($categoryIds);
        }

        $this->storePrimaryImages($product, $image, $hoverImage);

        return $product->fresh()->load('categories');
    }

    /** @param list<UploadedFile> $images */
    public function uploadImages(int|string $id, array $images): Product
    {
        $product = Product::findOrFail($id);
        $uploaded = [];

        foreach ($images as $file) {
            $uploaded[] = $this->media->addToCollection($file, $product, 'gallery', 'products');
        }

        if (! $product->image_url && count($uploaded)) {
            $product->update(['image_url' => Storage::disk('public')->url($uploaded[0]->path)]);
        }

        return $product->fresh()->load('media');
    }

    public function deleteImage(int|string $id, int|string $mediaId): Product
    {
        $product = Product::findOrFail($id);
        $media = $product->media()->whereKey($mediaId)->firstOrFail();

        Storage::disk('public')->delete($media->path);
        $media->delete();

        if ($product->image_url && str_contains($product->image_url, basename($media->path))) {
            $next = $product->media()->where('collection', 'gallery')->first();
            $product->update([
                'image_url' => $next ? Storage::disk('public')->url($next->path) : null,
            ]);
        }

        return $product->fresh()->load('media');
    }

    public function delete(int|string $id): void
    {
        Product::findOrFail($id)->delete();
    }

    private function storePrimaryImages(
        Product $product,
        ?UploadedFile $image,
        ?UploadedFile $hoverImage,
    ): void {
        if ($image) {
            $media = $this->media->upload($image, $product, 'image', 'products');
            $product->update(['image_url' => Storage::disk('public')->url($media->path)]);
        }

        if ($hoverImage) {
            $media = $this->media->upload($hoverImage, $product, 'hover', 'products');
            $product->update(['hover_image_url' => Storage::disk('public')->url($media->path)]);
        }
    }
}
