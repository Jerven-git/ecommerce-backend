<?php

namespace App\Modules\Media;

use App\Jobs\OptimizeProductImageJob;
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
        $this->ensureDirectoryExists($directory);

        // Replace existing file for this collection (logo/hero/about)
        $existing = $model->media()->where('collection', $collection)->first();

        // Store and persist the replacement before removing the current media.
        // A filesystem failure must never discard a working image or leave a
        // Media row whose path is the boolean false (rendered as /storage/0).
        $media = $this->saveMedia($file, $model, $collection, $directory);

        if ($existing) {
            Storage::disk('public')->delete($existing->path);
            $existing->delete();
        }

        return $media;
    }

    /**
     * Save a file to a gallery-style collection without blocking the request.
     *
     * The raw file is stored immediately so the image is accessible at once.
     * A queued job then resizes and re-encodes it in the background, replacing
     * the file in-place without changing its URL.
     */
    public function addToCollection(UploadedFile $file, Model $model, string $collection = 'gallery', ?string $directory = null): Media
    {
        $directory = $directory ?? strtolower(class_basename($model));
        $this->ensureDirectoryExists($directory);

        $path = $this->storeOriginal($file, $directory);
        $mime = $file->getClientMimeType();
        $ext = strtolower($file->getClientOriginalExtension() ?: 'jpg');

        $media = new Media([
            'hash' => hash_file('md5', $file->getRealPath()),
            'path' => $path,
            'format' => $ext,
            'mime_type' => $mime,
            'size' => $file->getSize(),
            'collection' => $collection,
            'processing_status' => in_array($mime, self::OPTIMIZABLE_MIMES, true) ? 'pending' : 'ready',
        ]);

        $model->media()->save($media);
        $media = $media->fresh();

        if (in_array($mime, self::OPTIMIZABLE_MIMES, true)) {
            OptimizeProductImageJob::dispatch($media->id);
        }

        return $media;
    }

    private function saveMedia(UploadedFile $file, Model $model, string $collection, string $directory): Media
    {
        [$path, $size, $mime, $format, $hash] = $this->storeOptimized($file, $directory);

        $media = new Media([
            'hash' => $hash,
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
     * [path, size, mime, extension, hash].
     */
    private function storeOptimized(UploadedFile $file, string $directory): array
    {
        $mime = $file->getClientMimeType();
        $ext = strtolower($file->getClientOriginalExtension() ?: 'jpg');

        if (! in_array($mime, self::OPTIMIZABLE_MIMES, true)) {
            $path = $this->storeOriginal($file, $directory);

            return [$path, $file->getSize(), $mime, $ext, hash_file('md5', $file->getRealPath())];
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
                'image/png' => $image->toPng(),
                'image/webp' => $image->toWebp(self::WEBP_QUALITY),
                default => $image->toJpeg(self::JPEG_QUALITY),
            };

            $bytes = (string) $encoded;
            $filename = Str::random(40).'.'.$ext;
            $path = $directory.'/'.$filename;
            $written = Storage::disk('public')->put($path, $bytes);
            if ($written !== true) {
                throw new \RuntimeException('Public media storage is not writable.');
            }

            return [$path, strlen($bytes), $mime, $ext, md5($bytes)];
        } catch (\Throwable $e) {
            Log::warning('Media: optimization failed, storing original', [
                'mime' => $mime,
                'error' => $e->getMessage(),
            ]);

            $path = $this->storeOriginal($file, $directory);

            return [$path, $file->getSize(), $mime, $ext, hash_file('md5', $file->getRealPath())];
        }
    }

    private function ensureDirectoryExists(string $directory): void
    {
        if (Storage::disk('public')->makeDirectory($directory) !== true) {
            throw new \RuntimeException('Public media storage is not writable.');
        }
    }

    private function storeOriginal(UploadedFile $file, string $directory): string
    {
        $path = Storage::disk('public')->put($directory, $file);

        if (! is_string($path) || trim($path) === '') {
            throw new \RuntimeException('Public media storage is not writable.');
        }

        return $path;
    }
}
