<?php

namespace App\Jobs;

use App\Models\Media;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\ImageManager;

class OptimizeProductImageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 120;

    private const MAX_WIDTH = 1920;

    private const MAX_HEIGHT = 1920;

    private const JPEG_QUALITY = 85;

    private const WEBP_QUALITY = 85;

    private const OPTIMIZABLE_MIMES = ['image/jpeg', 'image/png', 'image/webp'];

    public function __construct(public int $mediaId) {}

    public function handle(): void
    {
        $media = Media::find($this->mediaId);
        if (! $media) {
            return;
        }

        if (! in_array($media->mime_type, self::OPTIMIZABLE_MIMES, true)) {
            $media->update(['processing_status' => 'ready']);

            return;
        }

        $disk = 'public';
        $path = $media->path;

        if (! Storage::disk($disk)->exists($path)) {
            Log::warning('OptimizeProductImageJob: source file missing', [
                'media_id' => $this->mediaId,
                'path' => $path,
            ]);
            $media->update(['processing_status' => 'failed']);

            return;
        }

        try {
            $manager = extension_loaded('imagick')
                ? ImageManager::imagick()
                : ImageManager::gd();

            $image = $manager
                ->read(Storage::disk($disk)->path($path))
                ->orient()
                ->scaleDown(width: self::MAX_WIDTH, height: self::MAX_HEIGHT);

            $bytes = (string) match ($media->mime_type) {
                'image/png' => $image->toPng(),
                'image/webp' => $image->toWebp(self::WEBP_QUALITY),
                default => $image->toJpeg(self::JPEG_QUALITY),
            };

            Storage::disk($disk)->put($path, $bytes);

            $media->update([
                'hash' => md5($bytes),
                'size' => strlen($bytes),
                'processing_status' => 'ready',
            ]);
        } catch (\Throwable $e) {
            Log::warning('OptimizeProductImageJob: optimization failed, keeping original', [
                'media_id' => $this->mediaId,
                'error' => $e->getMessage(),
            ]);
            // Original file is still accessible — mark ready so it isn't stuck.
            $media->update(['processing_status' => 'ready']);
        }
    }
}
