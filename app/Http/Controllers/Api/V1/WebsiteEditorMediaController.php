<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Media;
use App\Models\Scopes\StoreScope;
use App\Models\SiteConfig;
use App\Models\SiteConfigDraft;
use App\Models\SiteConfigVersion;
use App\Support\WebsiteEditor\HomepageSnapshot;
use App\Support\WebsiteEditor\WebsiteEditorAssets;
use App\Support\WebsiteEditor\WebsiteEditorAudit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class WebsiteEditorMediaController extends Controller
{
    private const SLOT_LABELS = [
        'logo' => 'Header logo',
        'favicon' => 'Browser icon',
        'cart_icon' => 'Cart icon',
        'footer_logo' => 'Footer logo',
        'hero' => 'Home hero',
        'homepage_statement' => 'Statement image',
        'about' => 'About cover',
        'story_image_a' => 'Story section 1',
        'story_image_b' => 'Story section 2',
        'contact' => 'Contact cover',
        'blog' => 'Blog cover',
        'services' => 'Services cover',
    ];

    public function __construct(
        private readonly WebsiteEditorAssets $assets,
        private readonly WebsiteEditorAudit $audit,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'slot' => ['nullable', Rule::in(WebsiteEditorAssets::SLOTS)],
            'type' => ['nullable', Rule::in(['all', 'image', 'video'])],
            'search' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:24'],
        ]);
        $config = $this->configForAuthenticatedStore();
        $slot = $validated['slot'] ?? null;
        $type = $validated['type'] ?? 'all';
        $search = trim((string) ($validated['search'] ?? ''));
        $perPage = (int) ($validated['per_page'] ?? 12);

        $query = $config->media()
            ->whereIn('collection', WebsiteEditorAssets::libraryCollections())
            ->latest('id');

        if ($slot) {
            $maxBytes = in_array($slot, ['logo', 'favicon', 'cart_icon', 'footer_logo'], true)
                ? 2 * 1024 * 1024
                : 10 * 1024 * 1024;
            $query->where('size', '<=', $maxBytes)
                ->where(function ($mediaQuery) use ($slot): void {
                    $mediaQuery->whereIn('mime_type', ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/svg+xml']);
                    if ($slot === 'hero') {
                        $mediaQuery->orWhereIn('mime_type', ['video/mp4', 'video/webm']);
                    }
                });
        }

        if ($type === 'image') {
            $query->where('mime_type', 'like', 'image/%');
        } elseif ($type === 'video') {
            $query->where('mime_type', 'like', 'video/%');
        }

        if ($search !== '') {
            $query->where(function ($mediaQuery) use ($search): void {
                $mediaQuery
                    ->where('original_name', 'like', '%'.$search.'%')
                    ->orWhere('alt_text', 'like', '%'.$search.'%')
                    ->orWhere('collection', 'like', '%'.$search.'%');
            });
        }

        $page = $query->paginate($perPage);
        $references = $this->referencePayloads($config);

        return response()->json([
            'data' => $page->getCollection()
                ->map(fn (Media $media): array => $this->serialize($media, $references))
                ->values(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    public function reuse(Request $request, int $media, string $slot): JsonResponse
    {
        abort_unless(in_array($slot, WebsiteEditorAssets::SLOTS, true), 404);
        $config = $this->configForAuthenticatedStore();
        $source = $config->media()
            ->whereIn('collection', WebsiteEditorAssets::libraryCollections())
            ->findOrFail($media);
        $result = $this->assets->reuse($config, $source, $slot);

        $this->audit->record('media_reused', $config, $request->user(), [
            'source_media_id' => $source->id,
            'draft_media_id' => $result['id'],
            'slot' => $slot,
        ]);

        return response()->json($result);
    }

    public function update(Request $request, int $media): JsonResponse
    {
        $validated = $request->validate([
            'original_name' => ['nullable', 'string', 'max:255'],
            'alt_text' => ['nullable', 'string', 'max:255'],
        ]);
        $config = $this->configForAuthenticatedStore();
        $asset = $config->media()
            ->whereIn('collection', WebsiteEditorAssets::libraryCollections())
            ->findOrFail($media);
        $asset->update([
            'original_name' => trim((string) ($validated['original_name'] ?? '')) ?: null,
            'alt_text' => trim((string) ($validated['alt_text'] ?? '')) ?: null,
        ]);

        $this->audit->record('media_metadata_updated', $config, $request->user(), [
            'media_id' => $asset->id,
        ]);

        return response()->json(['data' => $this->serialize($asset->fresh(), $this->referencePayloads($config))]);
    }

    public function destroy(Request $request, int $media): JsonResponse
    {
        $config = $this->configForAuthenticatedStore();
        $asset = $config->media()
            ->whereIn('collection', WebsiteEditorAssets::libraryCollections())
            ->findOrFail($media);
        $usage = $this->usageFor($asset, $this->referencePayloads($config));

        if ($usage !== []) {
            return response()->json([
                'message' => 'This asset is still used by the website or retained history. Remove those references before deleting it.',
                'usage' => $usage,
            ], 409);
        }

        $path = DB::transaction(function () use ($config, $asset, $request): string {
            $mediaId = $asset->id;
            $path = $this->assets->deleteLibraryAsset($config, $asset);
            $this->audit->record('media_deleted', $config, $request->user(), [
                'media_id' => $mediaId,
            ]);

            return $path;
        });
        Storage::disk('public')->delete($path);

        return response()->json(['message' => 'Media asset deleted.']);
    }

    private function configForAuthenticatedStore(): SiteConfig
    {
        return SiteConfig::withoutGlobalScope(StoreScope::class)
            ->where('store_id', (int) request()->user()->store_id)
            ->firstOrFail();
    }

    /** @return array{live: array<string, mixed>, draft: array<string, mixed>|null, versions: array<int, array{id: int, payload: array<string, mixed>}>} */
    private function referencePayloads(SiteConfig $config): array
    {
        $draft = SiteConfigDraft::withoutGlobalScope(StoreScope::class)
            ->where('store_id', $config->store_id)
            ->first();
        $versions = SiteConfigVersion::withoutGlobalScope(StoreScope::class)
            ->where('store_id', $config->store_id)
            ->get(['id', 'payload'])
            ->map(fn (SiteConfigVersion $version): array => [
                'id' => $version->id,
                'payload' => $version->payload ?? [],
            ])
            ->all();

        return [
            'live' => HomepageSnapshot::fromConfig($config),
            'draft' => $draft?->payload,
            'versions' => $versions,
        ];
    }

    /** @param array{live: array<string, mixed>, draft: array<string, mixed>|null, versions: array<int, array{id: int, payload: array<string, mixed>}>} $references
     * @return array<string, mixed>
     */
    private function serialize(Media $media, array $references): array
    {
        $usage = $this->usageFor($media, $references);
        $archived = str_starts_with($media->collection, 'website_editor_asset_');

        return [
            'id' => $media->id,
            'name' => $media->original_name ?: basename($media->path),
            'url' => $media->url,
            'mime_type' => $media->mime_type,
            'size' => (int) $media->size,
            'alt_text' => $media->alt_text,
            'status' => str_starts_with($media->collection, 'website_editor_draft_')
                ? 'draft'
                : ($archived ? 'archived' : 'live'),
            'usage' => $usage,
            'can_delete' => $archived && $usage === [],
            'created_at' => $media->created_at?->toJSON(),
        ];
    }

    /** @param array{live: array<string, mixed>, draft: array<string, mixed>|null, versions: array<int, array{id: int, payload: array<string, mixed>}>} $references
     * @return array<int, string>
     */
    private function usageFor(Media $media, array $references): array
    {
        $usage = [];
        foreach (WebsiteEditorAssets::SLOTS as $slot) {
            $field = WebsiteEditorAssets::idField($slot);
            $label = self::SLOT_LABELS[$slot] ?? $slot;
            if ((int) ($references['live'][$field] ?? 0) === $media->id) {
                $usage[] = 'Live · '.$label;
            }
            if ((int) ($references['draft'][$field] ?? 0) === $media->id) {
                $usage[] = 'Draft · '.$label;
            }
        }

        $historyCount = collect($references['versions'])
            ->filter(function (array $version) use ($media): bool {
                foreach (WebsiteEditorAssets::SLOTS as $slot) {
                    if ((int) ($version['payload'][WebsiteEditorAssets::idField($slot)] ?? 0) === $media->id) {
                        return true;
                    }
                }

                return false;
            })
            ->count();
        if ($historyCount > 0) {
            $usage[] = $historyCount === 1 ? '1 published version' : $historyCount.' published versions';
        }

        return $usage;
    }
}
