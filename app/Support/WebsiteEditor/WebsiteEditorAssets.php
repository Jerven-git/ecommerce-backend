<?php

namespace App\Support\WebsiteEditor;

use App\Models\Media;
use App\Models\SiteConfig;
use App\Modules\Media\MediaService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class WebsiteEditorAssets
{
    public const SLOTS = [
        'logo',
        'favicon',
        'cart_icon',
        'footer_logo',
        'hero',
        'homepage_statement',
        'about',
        'story_image_a',
        'story_image_b',
        'contact',
        'blog',
        'services',
    ];

    private const FIELD_MAP = [
        'hero' => ['hero_media_id', 'hero_image_url'],
        'homepage_statement' => ['homepage_statement_media_id', 'homepage_statement_image_url'],
        'about' => ['about_media_id', 'about_image_url'],
        'story_image_a' => ['story_image_a_media_id', 'story_image_a_url'],
        'story_image_b' => ['story_image_b_media_id', 'story_image_b_url'],
        'contact' => ['contact_media_id', 'contact_image_url'],
        'blog' => ['blog_media_id', 'blog_image_url'],
        'services' => ['services_media_id', 'services_image_url'],
    ];

    public function __construct(private readonly MediaService $mediaService) {}

    public static function idField(string $slot): string
    {
        return self::FIELD_MAP[$slot][0] ?? $slot.'_media_id';
    }

    public static function urlField(string $slot): string
    {
        return self::FIELD_MAP[$slot][1] ?? $slot.'_url';
    }

    public static function draftCollection(string $slot): string
    {
        return 'website_editor_draft_'.$slot;
    }

    public static function archiveCollection(string $slot): string
    {
        return 'website_editor_asset_'.$slot;
    }

    /** @return array<int, string> */
    public static function libraryCollections(): array
    {
        return collect(self::SLOTS)
            ->flatMap(fn (string $slot): array => [
                $slot,
                self::draftCollection($slot),
                self::archiveCollection($slot),
            ])
            ->all();
    }

    /** @return array{id: int, collection: string, url: string, mime_type: string} */
    public function upload(SiteConfig $config, UploadedFile $file, string $slot): array
    {
        abort_unless(in_array($slot, self::SLOTS, true), 404);

        $media = $this->mediaService->addToCollection(
            $file,
            $config,
            self::draftCollection($slot),
            'site-config',
        );

        return [
            'id' => $media->id,
            'collection' => $slot,
            'url' => $media->url,
            'mime_type' => $media->mime_type,
        ];
    }

    /** @return array{id: int, collection: string, url: string, mime_type: string} */
    public function reuse(SiteConfig $config, Media $source, string $slot): array
    {
        abort_unless(in_array($slot, self::SLOTS, true), 404);
        abort_unless($source->imageable_type === $config->getMorphClass() && (int) $source->imageable_id === (int) $config->id, 404);
        abort_unless(in_array($source->collection, self::libraryCollections(), true), 404);

        if (! $this->isCompatible($source, $slot)) {
            throw ValidationException::withMessages([
                'media_id' => ['This asset does not meet the file type or size requirements for this placement.'],
            ]);
        }

        $allowedForSlot = [
            $slot,
            self::draftCollection($slot),
            self::archiveCollection($slot),
        ];
        $media = $source;

        if (! in_array($source->collection, $allowedForSlot, true)) {
            $extension = pathinfo($source->path, PATHINFO_EXTENSION) ?: $source->format;
            $copyPath = 'site-config/'.Str::random(40).($extension ? '.'.$extension : '');
            if (Storage::disk('public')->copy($source->path, $copyPath) !== true) {
                throw new \RuntimeException('The selected media could not be copied.');
            }

            try {
                $media = $config->media()->create([
                    'hash' => $source->hash,
                    'path' => $copyPath,
                    'format' => $source->format,
                    'mime_type' => $source->mime_type,
                    'size' => $source->size,
                    'collection' => self::draftCollection($slot),
                    'processing_status' => 'ready',
                    'alt_text' => $source->alt_text,
                    'original_name' => $source->original_name,
                ]);
            } catch (\Throwable $exception) {
                Storage::disk('public')->delete($copyPath);
                throw $exception;
            }
        }

        return [
            'id' => $media->id,
            'collection' => $slot,
            'url' => $media->url,
            'mime_type' => $media->mime_type,
        ];
    }

    public function isCompatible(Media $media, string $slot): bool
    {
        if (! in_array($slot, self::SLOTS, true)) {
            return false;
        }

        $brandSlot = in_array($slot, ['logo', 'favicon', 'cart_icon', 'footer_logo'], true);
        $maxBytes = ($brandSlot ? 2048 : 10240) * 1024;
        if ((int) $media->size > $maxBytes) {
            return false;
        }

        if (in_array($media->mime_type, ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/svg+xml'], true)) {
            return true;
        }

        return $slot === 'hero' && in_array($media->mime_type, ['video/mp4', 'video/webm'], true);
    }

    public function deleteLibraryAsset(SiteConfig $config, Media $media): string
    {
        abort_unless($media->imageable_type === $config->getMorphClass() && (int) $media->imageable_id === (int) $config->id, 404);
        abort_unless(str_starts_with($media->collection, 'website_editor_asset_'), 409, 'Only unused archived assets can be deleted.');

        $path = $media->path;
        $media->delete();

        return $path;
    }

    /** @return array<int, string> */
    public static function uploadRules(string $slot): array
    {
        abort_unless(in_array($slot, self::SLOTS, true), 404);

        $brandSlot = in_array($slot, ['logo', 'favicon', 'cart_icon', 'footer_logo'], true);
        $mimes = $slot === 'hero'
            ? 'jpeg,png,gif,webp,svg,svgz,mp4,webm'
            : 'jpeg,png,gif,webp,svg,svgz';

        return ['required', 'file', 'mimes:'.$mimes, 'max:'.($brandSlot ? 2048 : 10240)];
    }

    /** @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function normalizePayload(SiteConfig $config, array $payload): array
    {
        foreach (self::SLOTS as $slot) {
            $idField = self::idField($slot);
            $urlField = self::urlField($slot);

            if (! array_key_exists($idField, $payload)) {
                continue;
            }

            if ($payload[$idField] === null) {
                $payload[$urlField] = null;
                if ($slot === 'hero') {
                    $payload['hero_media_mime'] = null;
                }

                continue;
            }

            $media = $this->findAllowedAsset($config, $slot, (int) $payload[$idField]);
            if (! $media) {
                throw ValidationException::withMessages([
                    'payload.'.$idField => ['The selected asset is not available for this store.'],
                ]);
            }

            $payload[$idField] = $media->id;
            $payload[$urlField] = $media->url;
            if ($slot === 'hero') {
                $payload['hero_media_mime'] = $media->mime_type;
            }
        }

        return $payload;
    }

    /** @param array<string, mixed> $payload */
    public function publish(SiteConfig $config, array $payload): void
    {
        foreach (self::SLOTS as $slot) {
            $idField = self::idField($slot);
            if (! array_key_exists($idField, $payload)) {
                continue;
            }

            $targetId = $payload[$idField] === null ? null : (int) $payload[$idField];
            $target = $targetId ? $this->findAllowedAsset($config, $slot, $targetId) : null;
            if ($targetId && ! $target) {
                throw ValidationException::withMessages([
                    'payload.'.$idField => ['The selected asset is not available for this store.'],
                ]);
            }

            $current = $config->media()->where('collection', $slot)->first();
            if ($current && $current->id !== $target?->id) {
                $current->update(['collection' => self::archiveCollection($slot)]);
            }

            if ($target && $target->collection !== $slot) {
                $target->update(['collection' => $slot]);
            }
        }

        $this->purgeUnusedDraftAssets($config, $payload);
    }

    /** @param array<string, mixed> $payload */
    public function purgeUnusedDraftAssets(SiteConfig $config, array $payload): void
    {
        foreach (self::SLOTS as $slot) {
            $keepId = isset($payload[self::idField($slot)]) ? (int) $payload[self::idField($slot)] : null;
            $query = $config->media()->where('collection', self::draftCollection($slot));
            if ($keepId) {
                $query->whereKeyNot($keepId);
            }

            $query->get()->each(fn (Media $media) => $this->deleteMedia($media));
        }
    }

    public function purgeAllDraftAssets(SiteConfig $config): void
    {
        foreach (self::SLOTS as $slot) {
            $config->media()
                ->where('collection', self::draftCollection($slot))
                ->get()
                ->each(fn (Media $media) => $this->deleteMedia($media));
        }
    }

    private function findAllowedAsset(SiteConfig $config, string $slot, int $id): ?Media
    {
        return $config->media()
            ->whereKey($id)
            ->whereIn('collection', [
                $slot,
                self::draftCollection($slot),
                self::archiveCollection($slot),
            ])
            ->first();
    }

    private function deleteMedia(Media $media): void
    {
        Storage::disk('public')->delete($media->path);
        $media->delete();
    }
}
