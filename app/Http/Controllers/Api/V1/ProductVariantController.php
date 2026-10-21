<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreProductVariantRequest;
use App\Modules\Catalog\Application\CatalogOperationException;
use App\Modules\Catalog\Application\VariantManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductVariantController extends Controller
{
    public function index(int $productId, VariantManager $variants): JsonResponse
    {
        return response()->json(['data' => $variants->forProduct($productId)]);
    }

    public function sync(StoreProductVariantRequest $request, int $productId, VariantManager $variants): JsonResponse
    {
        try {
            return response()->json([
                'data' => $variants->sync($productId, $request->validated()),
            ]);
        } catch (CatalogOperationException $exception) {
            return response()->json(['message' => $exception->getMessage()], $exception->status);
        }
    }

    public function uploadVariantImage(
        Request $request,
        int $productId,
        int $variantId,
        VariantManager $variants,
    ): JsonResponse {
        $validated = $request->validate(['image' => 'required|image|max:2048']);

        return response()->json([
            'data' => $variants->uploadImage($productId, $variantId, $validated['image']),
        ]);
    }

    public function deleteVariantImage(int $productId, int $variantId, VariantManager $variants): JsonResponse
    {
        return response()->json([
            'data' => $variants->deleteImage($productId, $variantId),
        ]);
    }
}
