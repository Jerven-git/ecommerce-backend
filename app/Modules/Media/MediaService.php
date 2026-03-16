<?php

namespace App\Modules\Media;

use App\Models\Media;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class MediaService
{
    public function upload(UploadedFile $file, Model $model, string $collection = 'default', ?string $directory = null): Media
    {
        $directory = $directory ?? strtolower(class_basename($model));

        Storage::disk('public')->makeDirectory($directory);

        // Replace existing file for this collection (logo/hero/about)
        $existing = $model->media()->where('collection', $collection)->first();
        if ($existing) {
            Storage::disk('public')->delete($existing->path);
            $existing->delete();
        }

        $path = Storage::disk('public')->put($directory, $file);
        $hash = md5_file($file->getRealPath());

        $media = new Media([
            'hash' => $hash,
            'path' => $path,
            'format' => $file->getClientOriginalExtension(),
            'mime_type' => $file->getClientMimeType(),
            'size' => $file->getSize(),
            'collection' => $collection,
        ]);

        $model->media()->save($media);

        return $media->fresh();
    }

    /**
     * Add a file to a collection without replacing existing files (gallery-style).
     */
    public function addToCollection(UploadedFile $file, Model $model, string $collection = 'gallery', ?string $directory = null): Media
    {
        $directory = $directory ?? strtolower(class_basename($model));

        Storage::disk('public')->makeDirectory($directory);

        $path = Storage::disk('public')->put($directory, $file);
        $hash = md5_file($file->getRealPath());

        $media = new Media([
            'hash' => $hash,
            'path' => $path,
            'format' => $file->getClientOriginalExtension(),
            'mime_type' => $file->getClientMimeType(),
            'size' => $file->getSize(),
            'collection' => $collection,
        ]);

        $model->media()->save($media);

        return $media->fresh();
    }
}
