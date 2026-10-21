<?php

namespace App\Modules\Editorial\Application;

use App\Models\Post;
use App\Modules\Media\MediaService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class PostManager
{
    public function __construct(private readonly MediaService $media) {}

    public function create(array $attributes, ?UploadedFile $cover): Post
    {
        $attributes['slug'] = Post::generateUniqueSlug($attributes['title']);
        $this->resolvePublishedAt($attributes);
        $post = Post::create($attributes);
        $this->uploadCover($post, $cover);

        return $post->fresh()->load(['category', 'media']);
    }

    public function update(int $id, array $attributes, ?UploadedFile $cover): Post
    {
        $post = Post::findOrFail($id);
        if (isset($attributes['title']) && $attributes['title'] !== $post->title) {
            $attributes['slug'] = Post::generateUniqueSlug($attributes['title'], $post->id);
        }
        $this->resolvePublishedAt($attributes, $post);
        $post->update($attributes);
        $this->uploadCover($post, $cover);

        return $post->fresh()->load(['category', 'media']);
    }

    public function delete(int $id): void
    {
        Post::findOrFail($id)->delete();
    }

    private function uploadCover(Post $post, ?UploadedFile $cover): void
    {
        if ($cover) {
            $media = $this->media->upload($cover, $post, 'cover', 'posts');
            $post->update(['cover_image_url' => Storage::disk('public')->url($media->path)]);
        }
    }

    private function resolvePublishedAt(array &$attributes, ?Post $existing = null): void
    {
        $willPublish = array_key_exists('is_published', $attributes)
            ? (bool) $attributes['is_published']
            : (bool) ($existing?->is_published ?? false);

        if ($willPublish && empty($attributes['published_at']) && ! $existing?->published_at) {
            $attributes['published_at'] = now();
        }
    }
}
