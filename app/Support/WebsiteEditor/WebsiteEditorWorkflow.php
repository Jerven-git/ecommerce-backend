<?php

namespace App\Support\WebsiteEditor;

use App\Models\SiteConfigDraft;
use App\Models\Store;

class WebsiteEditorWorkflow
{
    /** @return array<string, mixed> */
    public function serialize(?SiteConfigDraft $draft, Store $store): array
    {
        if ($draft) {
            $draft->loadMissing([
                'reviewRequester:id,name',
                'reviewer:id,name',
                'scheduler:id,name',
            ]);
        }

        return [
            'status' => $draft?->review_status ?? 'draft',
            'requested_by_name' => $draft?->reviewRequester?->name,
            'requested_at' => $draft?->review_requested_at?->toJSON(),
            'reviewed_by_name' => $draft?->reviewer?->name,
            'reviewed_at' => $draft?->reviewed_at?->toJSON(),
            'note' => $draft?->review_note,
            'scheduled_publish_at' => $draft?->scheduled_publish_at?->toJSON(),
            'scheduled_by_name' => $draft?->scheduler?->name,
            'scheduled_release_note' => $draft?->scheduled_release_note,
            'timezone' => $store->resolvedTimezone(),
        ];
    }
}
