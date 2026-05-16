<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreProductVariantRequest;
use App\Models\Product;
use App\Models\ProductOption;
use App\Models\ProductOptionValue;
use App\Models\ProductVariant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ProductVariantController extends Controller
{
    public function index(int $productId): JsonResponse
    {
        $product = Product::findOrFail($productId);

        $options = $product->options()->with('values')->get();
        $variants = $product->variants()->with('optionValues')->get();

        return response()->json([
            'data' => [
                'options' => $options,
                'variants' => $variants,
            ],
        ]);
    }

    public function sync(StoreProductVariantRequest $request, int $productId): JsonResponse
    {
        $product = Product::findOrFail($productId);
        $data = $request->validated();

        $optionCount = count($data['options']);

        foreach ($data['variants'] as $variantData) {
            if (count($variantData['option_value_ids']) !== $optionCount) {
                return response()->json([
                    'message' => 'Each variant must supply exactly one value per option.',
                ], 422);
            }
        }

        DB::transaction(function () use ($product, $data) {
            $incomingOptionIds = [];
            $valueIdMap = [];

            foreach ($data['options'] as $optionData) {
                if (! empty($optionData['id'])) {
                    $option = ProductOption::findOrFail($optionData['id']);
                    $option->update([
                        'name' => $optionData['name'],
                        'position' => $optionData['position'],
                    ]);
                } else {
                    $option = $product->options()->create([
                        'name' => $optionData['name'],
                        'position' => $optionData['position'],
                    ]);
                }

                $incomingOptionIds[] = $option->id;
                $incomingValueIds = [];

                foreach ($optionData['values'] as $valueData) {
                    if (! empty($valueData['id'])) {
                        $value = ProductOptionValue::findOrFail($valueData['id']);
                        $value->update([
                            'label' => $valueData['label'],
                            'image_url' => $valueData['image_url'] ?? null,
                            'position' => $valueData['position'],
                        ]);
                    } else {
                        $value = $option->values()->create([
                            'label' => $valueData['label'],
                            'image_url' => $valueData['image_url'] ?? null,
                            'position' => $valueData['position'],
                        ]);
                    }

                    $incomingValueIds[] = $value->id;
                    $valueIdMap[$value->id] = $option->id;
                }

                $option->values()->whereNotIn('id', $incomingValueIds)->delete();
            }

            $product->options()->whereNotIn('id', $incomingOptionIds)->delete();

            $incomingVariantIds = [];

            foreach ($data['variants'] as $variantData) {
                $variantFields = [
                    'sku' => $variantData['sku'] ?? null,
                    'price' => $variantData['price'] ?? null,
                    'stock' => $variantData['stock'],
                    'image_url' => $variantData['image_url'] ?? null,
                    'is_active' => $variantData['is_active'],
                ];

                if (! empty($variantData['id'])) {
                    $variant = ProductVariant::findOrFail($variantData['id']);
                    $variant->update($variantFields);
                } else {
                    $variant = $product->variants()->create($variantFields);
                }

                $incomingVariantIds[] = $variant->id;

                $pivotData = [];
                foreach ($variantData['option_value_ids'] as $valueId) {
                    $pivotData[$valueId] = ['product_option_id' => $valueIdMap[$valueId]];
                }
                $variant->optionValues()->sync($pivotData);
            }

            $product->variants()->whereNotIn('id', $incomingVariantIds)->delete();
        });

        return $this->index($productId);
    }

    public function uploadVariantImage(Request $request, int $productId, int $variantId): JsonResponse
    {
        $request->validate(['image' => 'required|image|max:2048']);

        $variant = ProductVariant::where('product_id', $productId)->findOrFail($variantId);

        if ($variant->image_url) {
            $oldPath = parse_url($variant->image_url, PHP_URL_PATH);
            $oldPath = ltrim(str_replace('/storage', '', $oldPath), '/');
            Storage::disk('public')->delete($oldPath);
        }

        $path = Storage::disk('public')->put('products/variants', $request->file('image'));
        $variant->update(['image_url' => Storage::disk('public')->url($path)]);

        return response()->json(['data' => $variant->fresh()]);
    }

    public function deleteVariantImage(int $productId, int $variantId): JsonResponse
    {
        $variant = ProductVariant::where('product_id', $productId)->findOrFail($variantId);

        if ($variant->image_url) {
            $oldPath = parse_url($variant->image_url, PHP_URL_PATH);
            $oldPath = ltrim(str_replace('/storage', '', $oldPath), '/');
            Storage::disk('public')->delete($oldPath);
        }

        $variant->update(['image_url' => null]);

        return response()->json(['data' => $variant->fresh()]);
    }
}
