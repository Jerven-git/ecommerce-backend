<?php

namespace App\Support\WebsiteEditor;

use App\Models\Scopes\StoreScope;
use App\Models\SiteConfig;
use App\Models\SiteConfigDocument;
use Illuminate\Support\Arr;

final class WebsiteDocumentStore
{
    public const SCHEMA_VERSION = 1;

    public function __construct(private readonly WebsiteDocumentSanitizer $sanitizer) {}

    /** @return array<string, mixed> */
    public function read(SiteConfig $config): array
    {
        $document = $this->find($config);
        $stored = $document?->schema_version === self::SCHEMA_VERSION
            ? $document->payload
            : null;

        if (! is_array($stored)) {
            return HomepageSnapshot::fromLegacyConfig($config);
        }

        $resolved = WebsiteDocumentDefaults::published();
        $resolved['contact_email'] = $config->contact_email ?? $resolved['contact_email'];
        $resolved['contact_entries'] = $config->contact_entries ?: $resolved['contact_entries'];

        foreach (Arr::only($stored, WebsiteDocumentSchema::FIELDS) as $field => $value) {
            if ($value !== null || ($resolved[$field] ?? null) === null) {
                $resolved[$field] = $value;
            }
        }

        return $this->withMediaReferences($config, $resolved);
    }

    public function syncFromLegacy(SiteConfig $config, bool $refresh = true): SiteConfigDocument
    {
        $fresh = $refresh
            ? SiteConfig::withoutGlobalScope(StoreScope::class)->findOrFail($config->getKey())
            : $config;

        return $this->write($fresh, HomepageSnapshot::fromLegacyConfig($fresh));
    }

    public function ensure(SiteConfig $config): SiteConfigDocument
    {
        $document = $this->find($config)
            ?? $this->write($config, HomepageSnapshot::fromLegacyConfig($config));

        $config->setRelation('websiteDocument', $document);

        return $document;
    }

    /** @param array<string, mixed> $payload */
    public function write(SiteConfig $config, array $payload): SiteConfigDocument
    {
        $payload = $this->sanitizer->sanitize(HomepageSnapshot::forPersistence($payload));

        $document = SiteConfigDocument::withoutGlobalScope(StoreScope::class)->updateOrCreate(
            ['site_config_id' => $config->getKey()],
            [
                'store_id' => $config->store_id,
                'schema_version' => self::SCHEMA_VERSION,
                'payload' => $payload,
            ],
        );

        $config->setRelation('websiteDocument', $document);

        return $document;
    }

    private function find(SiteConfig $config): ?SiteConfigDocument
    {
        if ($config->relationLoaded('websiteDocument')) {
            $document = $config->getRelation('websiteDocument');

            return $document?->store_id === $config->store_id ? $document : null;
        }

        return SiteConfigDocument::withoutGlobalScope(StoreScope::class)
            ->where('site_config_id', $config->getKey())
            ->where('store_id', $config->store_id)
            ->first();
    }

    /**
     * Media remains relational. Add its current identifiers to snapshots without
     * replacing document-owned URLs that may belong to a restored version.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function withMediaReferences(SiteConfig $config, array $payload): array
    {
        $media = $config->relationLoaded('storefrontMedia')
            ? $config->getRelation('storefrontMedia')
            : $config->media()->whereIn('collection', WebsiteEditorAssets::SLOTS)->get();
        $mediaByCollection = $media->keyBy('collection');

        foreach (WebsiteEditorAssets::SLOTS as $slot) {
            $slotMedia = $mediaByCollection->get($slot);
            $idField = WebsiteEditorAssets::idField($slot);
            $urlField = WebsiteEditorAssets::urlField($slot);
            $payload[$idField] = $slotMedia?->id;

            if (! array_key_exists($urlField, $payload) || $payload[$urlField] === null) {
                $payload[$urlField] = $slotMedia?->url;
            }
        }

        if (($payload['hero_media_mime'] ?? null) === null) {
            $payload['hero_media_mime'] = $mediaByCollection->get('hero')?->mime_type;
        }

        return $payload;
    }
}
