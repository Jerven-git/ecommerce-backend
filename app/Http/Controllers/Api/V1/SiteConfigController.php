<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\SiteConfig\SiteConfigMediaService;
use App\Support\SiteConfig\SiteConfigPresenter;
use App\Support\SiteConfig\SiteConfigResolver;
use App\Support\SiteConfig\SiteConfigUpdater;
use App\Support\SiteConfig\SiteConfigUpdateRules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SiteConfigController extends Controller
{
    public function __construct(
        private readonly SiteConfigResolver $configs,
        private readonly SiteConfigPresenter $presenter,
        private readonly SiteConfigUpdater $updater,
        private readonly SiteConfigMediaService $media,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $presented = $this->presenter->present($this->configs->resolve($request), $request);

        return response()->json(['data' => $presented['data']])
            ->header('Content-Language', $presented['locale'])
            ->header('Vary', 'Accept-Language, Cookie');
    }

    public function update(Request $request): JsonResponse
    {
        $config = $this->configs->forAuthenticatedStore($request);
        $this->updater->update($config, $request->validate(SiteConfigUpdateRules::all()));

        return $this->show($request);
    }

    public function uploadWatchShopCard(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'file' => ['required', 'file', 'mimes:mp4,webm,mov,quicktime,gif,jpeg,png,webp', 'max:25600'],
        ]);

        return response()->json($this->media->uploadWatchShopCard(
            $this->configs->forAuthenticatedStore($request),
            $validated['file'],
        ));
    }

    public function deleteWatchShopCard(Request $request, string $cardId)
    {
        $config = $this->configs->forAuthenticatedStore($request, false);
        if (! $config) {
            return response()->noContent();
        }

        $this->media->deleteWatchShopCard($config, $cardId);

        return response()->noContent();
    }

    public function uploadMedia(Request $request, string $collection): JsonResponse
    {
        $validated = $request->validate([
            'file' => SiteConfigMediaService::uploadRules($collection),
        ]);

        try {
            $uploaded = $this->media->upload(
                $this->configs->forAuthenticatedStore($request),
                $validated['file'],
                $collection,
            );
        } catch (\RuntimeException $exception) {
            report($exception);

            return response()->json([
                'message' => 'The image could not be saved. Check that public storage is writable, then try again.',
            ], 500);
        }

        return response()->json($uploaded);
    }

    public function deleteMedia(Request $request, string $collection)
    {
        abort_unless(in_array($collection, SiteConfigMediaService::COLLECTIONS, true), 404);

        $config = $this->configs->forAuthenticatedStore($request, false);
        if (! $config) {
            return response()->noContent();
        }

        $this->media->delete($config, $collection);

        return response()->noContent();
    }
}
