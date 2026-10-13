<?php

namespace App\Support\WebsiteEditor;

use App\Models\SiteConfig;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Throwable;

class WebsiteEditorAudit
{
    /** @param array<string, mixed> $properties */
    public function record(
        string $event,
        SiteConfig $config,
        User $user,
        array $properties = [],
    ): void {
        activity('website_editor')
            ->causedBy($user)
            ->performedOn($config)
            ->withProperties(array_merge([
                'store_id' => $config->store_id,
            ], $properties))
            ->event($event)
            ->log($this->description($event));
    }

    /** @param array<string, mixed> $context */
    public function rejected(
        string $operation,
        string $reason,
        int $storeId,
        ?int $userId,
        array $context = [],
    ): void {
        Log::warning('Website editor operation rejected', array_merge([
            'operation' => $operation,
            'reason' => $reason,
            'store_id' => $storeId,
            'user_id' => $userId,
        ], $context));
    }

    /** @param array<string, mixed> $context */
    public function failed(
        string $operation,
        int $storeId,
        ?int $userId,
        Throwable $exception,
        array $context = [],
    ): void {
        Log::error('Website editor operation failed', array_merge([
            'operation' => $operation,
            'store_id' => $storeId,
            'user_id' => $userId,
            'exception' => $exception::class,
            'error' => $exception->getMessage(),
        ], $context));
    }

    private function description(string $event): string
    {
        return match ($event) {
            'draft_saved' => 'Website draft saved',
            'draft_discarded' => 'Website draft discarded',
            'website_published' => 'Website draft published',
            'version_restored' => 'Website version restored and published',
            default => 'Website editor changed',
        };
    }
}
