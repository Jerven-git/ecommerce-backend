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
 * Optimizes a homepage-showcase video upload so it plays without buffering lag:
 *
 *   - Re-encodes to H.264 (yuv420p) for universal browser support.
 *   - Caps width at 1920px so we are not shipping 4K from a hero block.
 *   - CRF 24, 2 Mbps target — good visual quality at ~2-3x smaller than the raw upload.
 *   - `-movflags +faststart` rewrites the file so the moov atom sits at the head,
 *     letting browsers begin playback before the file fully downloads. This is
 *     the single biggest factor in eliminating "first-play lag".
 *   - Generates a JPEG poster frame at t=1s so the video tile shows something
 *     instantly while the video itself is still buffering.
 *
 * Powered by pbmedia/laravel-ffmpeg (wraps the system `ffmpeg` binary).
 */
class OptimizeShowcaseVideoJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 2;

    public int $timeout = 600;

    public function __construct(public int $mediaId) {}

    public function handle(): void
    {
        /** @var Media|null $media */
        $media = Media::find($this->mediaId);
        if (! $media || $media->collection !== 'showcase_video') {
            return;
        }

        $config = SiteConfig::forDefaultStore();
        if (! $config) {
            return;
        }

        $disk = 'public';
        $sourcePath = $media->path;

        if (! Storage::disk($disk)->exists($sourcePath)) {
            Log::warning('OptimizeShowcaseVideoJob: source missing', ['path' => $sourcePath]);

            return;
        }

        $optimizedPath = 'site-config/showcase-'.Str::random(40).'.mp4';
        $posterPath = 'site-config/showcase-poster-'.Str::random(40).'.jpg';

        try {
            $video = FFMpeg::fromDisk($disk)->open($sourcePath);

            $format = (new X264('aac', 'libx264'))
                ->setKiloBitrate(2000)
                ->setAudioKiloBitrate(128)
                ->setAdditionalParameters([
                    '-movflags', '+faststart',
                    '-pix_fmt', 'yuv420p',
                    '-crf', '24',
                    '-preset', 'medium',
                    '-vf', "scale='min(1920,iw)':-2",
                ]);

            $video->export()
                ->toDisk($disk)
                ->inFormat($format)
                ->save($optimizedPath);

            $video->getFrameFromSeconds(1)
                ->export()
                ->toDisk($disk)
                ->save($posterPath);
        } catch (\Throwable $e) {
            Log::error('OptimizeShowcaseVideoJob: ffmpeg failed', [
                'media_id' => $media->id,
                'error' => $e->getMessage(),
            ]);
            $this->markStatus($config, 'failed');

            return;
        }

        $this->replaceMediaFile($media, $optimizedPath);

        $config->showcaseVideoPosterMedia()?->delete();
        $config->media()->create([
            'hash' => md5_file(Storage::disk($disk)->path($posterPath)),
            'path' => $posterPath,
            'format' => 'jpg',
            'mime_type' => 'image/jpeg',
            'size' => Storage::disk($disk)->size($posterPath),
            'collection' => 'showcase_video_poster',
        ]);

        $this->markStatus($config, 'ready');
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

    private function markStatus(SiteConfig $config, string $status): void
    {
        $showcase = $config->homepage_showcase ?? [];
        $showcase['video_status'] = $status;
        $config->update(['homepage_showcase' => $showcase]);
    }
}
