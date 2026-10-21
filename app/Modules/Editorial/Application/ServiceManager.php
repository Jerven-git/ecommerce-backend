<?php

namespace App\Modules\Editorial\Application;

use App\Models\Service;
use App\Modules\Media\MediaService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class ServiceManager
{
    public function __construct(private readonly MediaService $media) {}

    public function create(array $attributes, ?UploadedFile $cover): Service
    {
        $attributes['slug'] = Service::generateUniqueSlug($attributes['title']);
        $this->resolvePublishedAt($attributes);
        $service = Service::create($attributes);
        $this->uploadCover($service, $cover);

        return $service->fresh()->load(['category', 'media']);
    }

    public function update(int $id, array $attributes, ?UploadedFile $cover): Service
    {
        $service = Service::findOrFail($id);
        if (isset($attributes['title']) && $attributes['title'] !== $service->title) {
            $attributes['slug'] = Service::generateUniqueSlug($attributes['title'], $service->id);
        }
        $this->resolvePublishedAt($attributes, $service);
        $service->update($attributes);
        $this->uploadCover($service, $cover);

        return $service->fresh()->load(['category', 'media']);
    }

    public function delete(int $id): void
    {
        $service = Service::findOrFail($id);
        foreach ($service->media as $media) {
            Storage::disk('public')->delete($media->path);
            $media->delete();
        }
        $service->delete();
    }

    public function reorder(array $ids): void
    {
        foreach ($ids as $order => $id) {
            Service::whereKey($id)->update(['sort_order' => $order]);
        }
    }

    private function uploadCover(Service $service, ?UploadedFile $cover): void
    {
        if ($cover) {
            $media = $this->media->upload($cover, $service, 'cover', 'services');
            $service->update(['cover_image_url' => Storage::disk('public')->url($media->path)]);
        }
    }

    private function resolvePublishedAt(array &$attributes, ?Service $existing = null): void
    {
        $willPublish = array_key_exists('is_published', $attributes)
            ? (bool) $attributes['is_published']
            : (bool) ($existing?->is_published ?? false);

        if ($willPublish && empty($attributes['published_at']) && ! $existing?->published_at) {
            $attributes['published_at'] = now();
        }
    }
}
