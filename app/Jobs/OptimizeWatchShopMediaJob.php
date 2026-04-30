<?php

namespace App\Jobs;

use App\Models\Media;
use App\Models\SiteConfig;
use FFMpeg\Format\Video\X264;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use ProtoneMedia\LaravelFFMpeg\Support\FFMpeg;

/**
 * Optimizes one Watch & Shop card's media so the carousel stays smooth even
 * when several cards share the viewport.
 *
 * Handles three input shapes:
 *   - **Video** (mp4/webm/mov): re-encode H.264 + faststart, cap 720p, generate poster.
 *   - **GIF**: convert to looping MP4 — same H.264 pipeline. A typical 5s GIF is
 *     5-15MB; the same content as MP4 is 200-800KB, so this is a strict win
 *     (smaller bytes, smoother playback, identical autoplay-loop behaviour).
 *   - **Image** (JPEG/PNG/WebP): nothing to do — `MediaService::storeOptimized`
 *     already capped + re-encoded the image at upload time.
 *
 * Speed/quality tradeoff: `-preset veryfast` is 6-10x faster than `medium` for
 * only ~10-15% larger output. For short product clips on a homepage carousel
 * this is the right call — the visual difference is invisible, but admins
 * watching the optimization spinner notice the wall-clock difference.
 *
 * Status is tracked on the `media.processing_status` column (rather than in
 * SiteConfig JSON) so it's owned by the same row as the file it describes.
 * That avoids a race where the admin saves the homepage_watch_shop block
 * during processing and clobbers the status.
 *
 * Powered by pbmedia/laravel-ffmpeg (wraps the system `ffmpeg` binary).
 */
class OptimizeWatchShopMediaJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 2;

    public int $timeout = 600;

    public function __construct(
        public int $mediaId,
        public string $cardId,
    ) {}

    public function handle(): void
    {
        /** @var Media|null $media */
        $media = Media::find($this->mediaId);
        $config = SiteConfig::first();
        if (! $media || ! $config) {
            return;
        }

        if (! $this->isOptimizable($media)) {
            $media->update(['processing_status' => 'ready']);

            return;
        }

        $disk = 'public';
        if (! Storage::disk($disk)->exists($media->path)) {
            Log::warning('OptimizeWatchShopMediaJob: source missing', ['path' => $media->path]);
            $media->update(['processing_status' => 'failed']);

            return;
        }

        $optimizedPath = 'site-config/watch-shop-'.Str::random(40).'.mp4';
        $posterPath = 'site-config/watch-shop-poster-'.Str::random(40).'.jpg';

        try {
            $video = FFMpeg::fromDisk($disk)->open($media->path);

            $format = (new X264('aac', 'libx264'))
                ->setKiloBitrate(1200)
                ->setAudioKiloBitrate(96)
                ->setAdditionalParameters([
                    '-movflags', '+faststart',
                    '-pix_fmt', 'yuv420p',
                    '-crf', '26',
                    '-preset', 'veryfast',
                    // Cap the longer side at 720px while preserving aspect ratio
                    // (works for both portrait phone clips and landscape clips).
                    '-vf', "scale='if(gt(iw,ih),min(720,iw),-2)':'if(gt(iw,ih),-2,min(720,ih))'",
                ]);

            $video->export()
                ->toDisk($disk)
                ->inFormat($format)
                ->save($optimizedPath);

            $video->getFrameFromSeconds(0)
                ->export()
                ->toDisk($disk)
                ->save($posterPath);
        } catch (\Throwable $e) {
            Log::error('OptimizeWatchShopMediaJob: ffmpeg failed', [
                'media_id' => $media->id,
                'card_id' => $this->cardId,
                'error' => $e->getMessage(),
            ]);
            $media->update(['processing_status' => 'failed']);

            return;
        }

        $this->replaceMediaFile($media, $optimizedPath);

        // Drop any previous poster for this card and write the new one.
        $posterCollection = $this->posterCollection();
        $existingPoster = $config->media()->where('collection', $posterCollection)->first();
        if ($existingPoster) {
            Storage::disk($disk)->delete($existingPoster->path);
            $existingPoster->delete();
        }

        $config->media()->create([
            'hash' => md5_file(Storage::disk($disk)->path($posterPath)),
            'path' => $posterPath,
            'format' => 'jpg',
            'mime_type' => 'image/jpeg',
            'size' => Storage::disk($disk)->size($posterPath),
            'collection' => $posterCollection,
        ]);

        $media->update(['processing_status' => 'ready']);
    }

    private function isOptimizable(Media $media): bool
    {
        $mime = strtolower((string) $media->mime_type);

        // Videos (any container) and GIFs go through ffmpeg.
        // Plain images come in already-optimized from MediaService.
        return str_starts_with($mime, 'video/') || $mime === 'image/gif';
    }

    private function replaceMediaFile(Media $media, string $newPath): void
    {
        $disk = 'public';
        Storage::disk($disk)->delete($media->path);

        $media->update([
            'path' => $newPath,
            'hash' => md5_file(Storage::disk($disk)->path($newPath)),
            'format' => 'mp4',
            'mime_type' => 'video/mp4',
            'size' => Storage::disk($disk)->size($newPath),
        ]);
    }

    private function posterCollection(): string
    {
        return 'watch_shop_poster:'.$this->cardId;
    }
}
