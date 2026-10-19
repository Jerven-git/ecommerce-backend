<?php

namespace App\Support\SiteConfig;

use App\Jobs\OptimizeShowcaseVideoJob;
use App\Jobs\OptimizeWatchShopMediaJob;
use App\Models\Media;
use App\Models\SiteConfig;
use App\Modules\Media\MediaService;
use App\Support\WebsiteEditor\HomepageSnapshot;
use App\Support\WebsiteEditor\WebsiteDocumentStore;
use App\Support\WebsiteEditor\WebsiteEditorAssets;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

final class SiteConfigMediaService
{
    private const DOCUMENT_MEDIA_SLOTS = [
        'hero',
        'homepage_statement',
        'about',
        'story_image_a',
        'story_image_b',
        'contact',
        'blog',
        'services',
    ];

    public const COLLECTIONS = [
        'logo', 'favicon', 'cart_icon', 'footer_logo', 'loader_logo', 'hero', 'about', 'contact',
        'blog', 'services', 'showcase_video', 'homepage_statement', 'story_image_a', 'story_image_b',
    ];

    public function __construct(
        private readonly MediaService $media,
        private readonly WebsiteDocumentStore $documents,
    ) {}

    /** @return array<int, string> */
    public static function uploadRules(string $collection): array
    {
        abort_unless(in_array($collection, self::COLLECTIONS, true), 404);

        $max = match (true) {
            in_array($collection, ['logo', 'favicon', 'cart_icon', 'footer_logo', 'loader_logo'], true) => 2048,
            $collection === 'showcase_video' => 25600,
            default => 10120,
        };
        $mimes = match ($collection) {
            'hero' => 'jpeg,png,gif,webp,svg,svgz,mp4,webm',
            'showcase_video' => 'mp4,webm,mov,quicktime',
            default => 'jpeg,png,gif,webp,svg,svgz',
        };

        return ['required', 'file', "mimes:$mimes", "max:$max"];
    }

    /** @return array{card_id: string, media_id: int, media_url: string, media_kind: string, media_status: string} */
    public function uploadWatchShopCard(SiteConfig $config, UploadedFile $file): array
    {
        $cardId = (string) Str::uuid();
        $media = $this->media->addToCollection($file, $config, 'watch_shop_card', 'site-config');
        $mime = strtolower((string) $media->mime_type);
        $isVideoLike = str_starts_with($mime, 'video/') || $mime === 'image/gif';
        $status = $isVideoLike ? 'processing' : 'ready';

        $media->update(['processing_status' => $status]);

        if ($isVideoLike) {
            OptimizeWatchShopMediaJob::dispatch($media->id, $cardId, $config->store_id);
        }

        return [
            'card_id' => $cardId,
            'media_id' => $media->id,
            'media_url' => $media->url,
            'media_kind' => str_starts_with($mime, 'video/') ? 'video' : 'image',
            'media_status' => $status,
        ];
    }

    public function deleteWatchShopCard(SiteConfig $config, string $cardId): void
    {
        $cards = collect($config->homepage_watch_shop['cards'] ?? []);
        $prior = $cards->firstWhere('id', $cardId);
        $mediaId = $prior !== null && isset($prior['media_id']) ? (int) $prior['media_id'] : null;

        $this->deleteWatchShopCardAssets($config, $cardId, $mediaId);

        $watchShop = $config->homepage_watch_shop ?? [];
        $watchShop['cards'] = $cards
            ->reject(fn ($card) => ($card['id'] ?? null) === $cardId)
            ->values()
            ->all();
        $config->update(['homepage_watch_shop' => $watchShop]);
    }

    /**
     * @param  array<string, mixed>  $incoming
     * @return array<string, mixed>
     */
    public function reconcileWatchShop(SiteConfig $config, array $incoming): array
    {
        $existing = is_array($config->homepage_watch_shop) ? $config->homepage_watch_shop : [];
        $existingCards = collect($existing['cards'] ?? [])->keyBy('id');
        $incomingIds = collect($incoming['cards'] ?? [])->pluck('id')->filter()->all();

        foreach ($existingCards->keys()->diff($incomingIds) as $removedId) {
            $prior = $existingCards->get($removedId);
            $this->deleteWatchShopCardAssets(
                $config,
                (string) $removedId,
                isset($prior['media_id']) ? (int) $prior['media_id'] : null,
            );
        }

        return $incoming;
    }

    /** @return array{id: int, collection: string, url: string} */
    public function upload(SiteConfig $config, UploadedFile $file, string $collection): array
    {
        abort_unless(in_array($collection, self::COLLECTIONS, true), 404);
        $media = $this->media->upload($file, $config, $collection, 'site-config');

        if ($collection === 'showcase_video') {
            $this->deleteCollection($config, 'showcase_video_poster');
            $showcase = $config->homepage_showcase ?? [];
            $showcase['video_status'] = 'processing';
            $config->update(['homepage_showcase' => $showcase]);
            OptimizeShowcaseVideoJob::dispatch($media->id, $config->store_id);
        } elseif (in_array($collection, self::DOCUMENT_MEDIA_SLOTS, true)) {
            $this->syncDocumentMedia($config, $collection, $media);
            $config->touch();
        } else {
            $config->touch();
        }

        return ['id' => $media->id, 'collection' => $media->collection, 'url' => $media->url];
    }

    public function delete(SiteConfig $config, string $collection): void
    {
        abort_unless(in_array($collection, self::COLLECTIONS, true), 404);

        foreach ($collection === 'showcase_video'
            ? ['showcase_video', 'showcase_video_poster']
            : [$collection] as $collectionToClear) {
            $this->deleteCollection($config, $collectionToClear);
        }

        if ($collection === 'showcase_video') {
            $showcase = $config->homepage_showcase ?? [];
            $showcase['video_status'] = 'idle';
            $config->update(['homepage_showcase' => $showcase]);
        } elseif (in_array($collection, self::DOCUMENT_MEDIA_SLOTS, true)) {
            $this->syncDocumentMedia($config, $collection);
            $config->touch();
        } else {
            $config->touch();
        }
    }

    private function deleteWatchShopCardAssets(SiteConfig $config, string $cardId, ?int $mediaId): void
    {
        if ($mediaId !== null && ($media = Media::find($mediaId))) {
            Storage::disk('public')->delete($media->path);
            $media->delete();
        }

        $this->deleteCollection($config, 'watch_shop_poster:'.$cardId);
    }

    private function deleteCollection(SiteConfig $config, string $collection): void
    {
        $media = $config->media()->where('collection', $collection)->first();
        if ($media) {
            Storage::disk('public')->delete($media->path);
            $media->delete();
        }
    }

    private function syncDocumentMedia(SiteConfig $config, string $slot, ?Media $media = null): void
    {
        $payload = HomepageSnapshot::fromConfig($config);
        $payload[WebsiteEditorAssets::idField($slot)] = $media?->id;
        $payload[WebsiteEditorAssets::urlField($slot)] = $media?->url;
        if ($slot === 'hero') {
            $payload['hero_media_mime'] = $media?->mime_type;
        }

        $this->documents->write($config, $payload);
    }
}
