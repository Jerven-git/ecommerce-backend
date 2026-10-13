<?php

namespace App\Console\Commands;

use App\Models\Media;
use App\Models\SiteConfigDraft;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class MonitorWebsiteEditorHealth extends Command
{
    protected $signature = 'website-editor:monitor
        {--draft-minutes=1440 : Age in minutes before a private draft is considered stale}
        {--media-minutes=30 : Age in minutes before pending media is considered abandoned}';

    protected $description = 'Report stale website drafts and media processing that has stopped progressing';

    public function handle(): int
    {
        $draftMinutes = max(1, (int) $this->option('draft-minutes'));
        $mediaMinutes = max(1, (int) $this->option('media-minutes'));

        $staleDrafts = SiteConfigDraft::withoutGlobalScopes()
            ->where('updated_at', '<=', now()->subMinutes($draftMinutes))
            ->orderBy('id')
            ->get(['id', 'store_id', 'updated_at']);

        $stalledMedia = Media::query()
            ->whereIn('processing_status', ['pending', 'processing'])
            ->where('updated_at', '<=', now()->subMinutes($mediaMinutes))
            ->orderBy('id')
            ->get(['id', 'imageable_type', 'imageable_id', 'collection', 'processing_status', 'updated_at']);

        if ($staleDrafts->isNotEmpty()) {
            Log::warning('Website editor stale drafts detected', [
                'count' => $staleDrafts->count(),
                'drafts' => $staleDrafts->map->only(['id', 'store_id', 'updated_at'])->all(),
            ]);
        }

        if ($stalledMedia->isNotEmpty()) {
            Log::warning('Website editor stalled media processing detected', [
                'count' => $stalledMedia->count(),
                'media' => $stalledMedia->map->only([
                    'id', 'imageable_type', 'imageable_id', 'collection', 'processing_status', 'updated_at',
                ])->all(),
            ]);
        }

        $this->components->info(sprintf(
            'Website editor health check complete: %d stale draft(s), %d stalled media item(s).',
            $staleDrafts->count(),
            $stalledMedia->count(),
        ));

        return self::SUCCESS;
    }
}
