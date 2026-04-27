<?php

namespace App\Modules\Media;

use App\Models\Media;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\ImageManager;

class MediaService
{
    private const MAX_WIDTH = 1920;
    private const MAX_HEIGHT = 1920;
    private const JPEG_QUALITY = 85;
    private const WEBP_QUALITY = 85;
    private const OPTIMIZABLE_MIMES = ['image/jpeg', 'image/png', 'image/webp'];

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

        return $this->saveMedia($file, $model, $collection, $directory);
    }

    /**
     * Add a file to a collection without replacing existing files (gallery-style).
     */
    public function addToCollection(UploadedFile $file, Model $model, string $collection = 'gallery', ?string $directory = null): Media
    {
        $directory = $directory ?? strtolower(class_basename($model));
        Storage::disk('public')->makeDirectory($directory);

        return $this->saveMedia($file, $model, $collection, $directory);
    }

    private function saveMedia(UploadedFile $file, Model $model, string $collection, string $directory): Media
    {
        [$path, $size, $mime, $format] = $this->storeOptimized($file, $directory);

        $media = new Media([
            'hash' => md5_file(Storage::disk('public')->path($path)),
            'path' => $path,
            'format' => $format,
            'mime_type' => $mime,
            'size' => $size,
            'collection' => $collection,
        ]);

        $model->media()->save($media);

        return $media->fresh();
    }

    /**
     * Writes an uploaded file to the public disk. For JPEG/PNG/WebP, the image is
     * EXIF-rotated, capped to 1920px on the longer side, and re-encoded at 85%
     * quality. Other formats (SVG, GIF, etc.) pass through unchanged. Returns
     * [path, size, mime, extension].
     */
    private function storeOptimized(UploadedFile $file, string $directory): array
    {
        $mime = $file->getClientMimeType();
        $ext = strtolower($file->getClientOriginalExtension() ?: 'jpg');

        if (!in_array($mime, self::OPTIMIZABLE_MIMES, true)) {
            $path = Storage::disk('public')->put($directory, $file);
            return [$path, $file->getSize(), $mime, $ext];
        }

        try {
            $manager = extension_loaded('imagick')
                ? ImageManager::imagick()
                : ImageManager::gd();

            $image = $manager
                ->read($file->getRealPath())
                ->orient()
                ->scaleDown(width: self::MAX_WIDTH, height: self::MAX_HEIGHT);

            $encoded = match ($mime) {
                'image/png'  => $image->toPng(),
                'image/webp' => $image->toWebp(self::WEBP_QUALITY),
                default      => $image->toJpeg(self::JPEG_QUALITY),
            };

            $bytes = (string) $encoded;
            $filename = Str::random(40) . '.' . $ext;
            $path = $directory . '/' . $filename;
            Storage::disk('public')->put($path, $bytes);

            return [$path, strlen($bytes), $mime, $ext];
        } catch (\Throwable $e) {
            Log::warning('Media: optimization failed, storing original', [
                'mime' => $mime,
                'error' => $e->getMessage(),
            ]);

            $path = Storage::disk('public')->put($directory, $file);
            return [$path, $file->getSize(), $mime, $ext];
        }
    }
}
