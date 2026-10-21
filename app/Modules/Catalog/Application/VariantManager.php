<?php

namespace App\Modules\Catalog\Application;

use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class VariantManager
{
    /** @return array{options: mixed, variants: mixed} */
    public function forProduct(int $productId): array
    {
        $product = Product::findOrFail($productId);

        return [
            'options' => $product->options()->with('values')->get(),
            'variants' => $product->variants()->with('optionValues')->get(),
        ];
    }

    /** @param array<string, mixed> $data
     * @return array{options: mixed, variants: mixed}
     */
    public function sync(int $productId, array $data): array
    {
        $product = Product::findOrFail($productId);
        $optionCount = count($data['options']);

        foreach ($data['variants'] as $variant) {
            if (count($variant['option_value_ids']) !== $optionCount) {
                throw new CatalogOperationException('Each variant must supply exactly one value per option.');
            }
        }

        DB::transaction(function () use ($product, $data) {
            $incomingOptionIds = [];
            $valueOptionMap = [];

            foreach ($data['options'] as $optionData) {
                if (! empty($optionData['id'])) {
                    $option = $product->options()->findOrFail($optionData['id']);
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
                        $value = $option->values()->findOrFail($valueData['id']);
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
                    $valueOptionMap[$value->id] = $option->id;
                }

                $option->values()->whereNotIn('id', $incomingValueIds)->delete();
            }

            $product->options()->whereNotIn('id', $incomingOptionIds)->delete();
            $incomingVariantIds = [];

            foreach ($data['variants'] as $variantData) {
                $fields = [
                    'sku' => $variantData['sku'] ?? null,
                    'price' => $variantData['price'] ?? null,
                    'stock' => $variantData['stock'],
                    'image_url' => $variantData['image_url'] ?? null,
                    'is_active' => $variantData['is_active'],
                ];

                if (! empty($variantData['id'])) {
                    $variant = $product->variants()->findOrFail($variantData['id']);
                    $variant->update($fields);
                } else {
                    $variant = $product->variants()->create($fields);
                }

                $incomingVariantIds[] = $variant->id;
                $pivotData = [];
                $variantOptionIds = [];

                foreach ($variantData['option_value_ids'] as $valueId) {
                    if (! isset($valueOptionMap[$valueId])) {
                        throw ValidationException::withMessages([
                            'variants' => ['Every variant value must belong to an option in this product payload.'],
                        ]);
                    }

                    $optionId = $valueOptionMap[$valueId];
                    $variantOptionIds[] = $optionId;
                    $pivotData[$valueId] = ['product_option_id' => $optionId];
                }

                if (count(array_unique($variantOptionIds)) !== count($data['options'])) {
                    throw ValidationException::withMessages([
                        'variants' => ['Each variant must supply exactly one value per option.'],
                    ]);
                }

                $variant->optionValues()->sync($pivotData);
            }

            $product->variants()->whereNotIn('id', $incomingVariantIds)->delete();
        });

        return $this->forProduct($productId);
    }

    public function uploadImage(int $productId, int $variantId, UploadedFile $image): ProductVariant
    {
        $variant = $this->variantForProduct($productId, $variantId);
        $this->deleteStoredImage($variant);

        $path = Storage::disk('public')->put('products/variants', $image);
        $variant->update(['image_url' => Storage::disk('public')->url($path)]);

        return $variant->fresh();
    }

    public function deleteImage(int $productId, int $variantId): ProductVariant
    {
        $variant = $this->variantForProduct($productId, $variantId);
        $this->deleteStoredImage($variant);
        $variant->update(['image_url' => null]);

        return $variant->fresh();
    }

    private function variantForProduct(int $productId, int $variantId): ProductVariant
    {
        return Product::findOrFail($productId)->variants()->findOrFail($variantId);
    }

    private function deleteStoredImage(ProductVariant $variant): void
    {
        if (! $variant->image_url) {
            return;
        }

        $path = parse_url($variant->image_url, PHP_URL_PATH);
        $path = ltrim(str_replace('/storage', '', $path), '/');
        Storage::disk('public')->delete($path);
    }
}
