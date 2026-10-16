<?php

namespace App\Console\Commands;

use App\Models\Scopes\StoreScope;
use App\Models\SiteConfigDraft;
use App\Models\User;
use App\Support\WebsiteEditor\WebsiteDraftPublisher;
use App\Support\WebsiteEditor\WebsiteDraftPublishException;
use App\Support\WebsiteEditor\WebsiteEditorAudit;
use Illuminate\Console\Command;
use Throwable;

class PublishScheduledWebsiteDrafts extends Command
{
    protected $signature = 'website-editor:publish-scheduled {--limit=50}';

    protected $description = 'Publish approved website drafts whose scheduled time has arrived';

    public function handle(WebsiteDraftPublisher $publisher, WebsiteEditorAudit $audit): int
    {
        $limit = max(1, min(200, (int) $this->option('limit')));
        $drafts = SiteConfigDraft::withoutGlobalScope(StoreScope::class)
            ->where('review_status', 'approved')
            ->whereNotNull('scheduled_publish_at')
            ->where('scheduled_publish_at', '<=', now())
            ->orderBy('scheduled_publish_at')
            ->limit($limit)
            ->get();
        $published = 0;

        foreach ($drafts as $draft) {
            $user = $draft->scheduled_by
                ? User::query()->find($draft->scheduled_by)
                : null;

            if (! $user || $user->isDisabled() || (int) $user->store_id !== (int) $draft->store_id || ! $user->isAdminLike()) {
                $scheduledBy = $draft->scheduled_by;
                $draft->update([
                    'review_status' => 'changes_requested',
                    'review_note' => 'The scheduled publisher is no longer available. Request approval and schedule again.',
                    'scheduled_publish_at' => null,
                    'scheduled_by' => null,
                    'scheduled_release_note' => null,
                ]);
                $audit->rejected('scheduled_publish', 'scheduler_unavailable', (int) $draft->store_id, $scheduledBy, [
                    'draft_id' => $draft->id,
                ]);
                continue;
            }

            try {
                $publisher->publish(
                    (int) $draft->store_id,
                    $user,
                    $draft->scheduled_release_note,
                    true,
                );
                $published++;
            } catch (WebsiteDraftPublishException $exception) {
                SiteConfigDraft::withoutGlobalScope(StoreScope::class)
                    ->whereKey($draft->id)
                    ->update([
                        'review_status' => 'changes_requested',
                        'review_note' => $exception->getMessage(),
                        'scheduled_publish_at' => null,
                        'scheduled_by' => null,
                        'scheduled_release_note' => null,
                    ]);
            } catch (Throwable $exception) {
                $audit->failed('scheduled_publish', (int) $draft->store_id, $user->id, $exception, [
                    'draft_id' => $draft->id,
                ]);
                report($exception);
            }
        }

        $this->info("Published {$published} scheduled website draft(s).");

        return self::SUCCESS;
    }
}
