<?php

namespace App\Support\SiteConfig;

use App\Models\SiteConfig;
use App\Support\WebsiteEditor\HomepageSnapshot;
use App\Support\WebsiteEditor\WebsiteDocumentSchema;
use App\Support\WebsiteEditor\WebsiteDocumentStore;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

final class SiteConfigUpdater
{
    private const DOCUMENT_FIELDS_RETAINED_OPERATIONALLY = [
        'contact_email',
        'contact_entries',
    ];

    public function __construct(
        private readonly SiteConfigMediaService $media,
        private readonly WebsiteDocumentStore $documents,
    ) {}

    /** @param array<string, mixed> $validated */
    public function update(SiteConfig $config, array $validated): void
    {
        DB::transaction(fn () => $this->persist($config, $validated));
    }

    /** @param array<string, mixed> $validated */
    private function persist(SiteConfig $config, array $validated): void
    {
        if (array_key_exists('contact_email', $validated) && $validated['contact_email'] === null) {
            $validated['contact_email'] = '';
        }

        if (isset($validated['theme'])) {
            $validated['theme'] = array_merge(
                $config->theme ?? SiteConfig::THEME_DEFAULTS,
                array_filter($validated['theme'], fn ($value) => $value !== null),
            );
        }

        if (isset($validated['homepage_showcase'])) {
            $existing = is_array($config->homepage_showcase) ? $config->homepage_showcase : [];
            $existingStatus = $existing['video_status'] ?? null;
            if ($existingStatus !== null) {
                $validated['homepage_showcase']['video_status'] = $existingStatus;
            }
        }

        if (isset($validated['homepage_watch_shop'])) {
            $validated['homepage_watch_shop'] = $this->media->reconcileWatchShop(
                $config,
                $validated['homepage_watch_shop'],
            );
        }

        $documentChanges = Arr::only($validated, WebsiteDocumentSchema::FIELDS);
        $operationalFields = array_values(array_diff(
            array_keys($validated),
            array_diff(WebsiteDocumentSchema::FIELDS, self::DOCUMENT_FIELDS_RETAINED_OPERATIONALLY),
        ));
        $operationalChanges = Arr::only($validated, $operationalFields);

        if ($operationalChanges !== []) {
            $config->update($operationalChanges);
        }

        if ($documentChanges !== []) {
            $this->documents->write(
                $config,
                array_replace(HomepageSnapshot::fromConfig($config), $documentChanges),
            );

            if ($operationalChanges === []) {
                $config->touch();
            }
        }
    }
}
