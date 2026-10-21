<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\StoreProductRequest;
use App\Http\Requests\Catalog\UpdateProductRequest;
use App\Modules\Catalog\Application\ProductManager;
use App\Modules\Catalog\Application\ProductQuery;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    public function index(Request $request, ProductQuery $products)
    {
        return response()->json($products->list($request));
    }

    public function show(string $slug, ProductQuery $products)
    {
        return response()->json(['data' => $products->show($slug)]);
    }

    public function uploadImages(Request $request, int|string $id, ProductManager $products)
    {
        $validated = $request->validate([
            'images' => 'required|array|min:1',
            'images.*' => 'image|max:2048',
        ]);

        return response()->json([
            'message' => count($validated['images']).' image(s) uploaded',
            'data' => $products->uploadImages($id, $validated['images']),
        ]);
    }

    public function deleteImage(int|string $id, int|string $mediaId, ProductManager $products)
    {
        return response()->json([
            'message' => 'Image deleted',
            'data' => $products->deleteImage($id, $mediaId),
        ]);
    }

    public function store(StoreProductRequest $request, ProductManager $products)
    {
        return response()->json([
            'message' => 'Product created successfully',
            'data' => $products->create(
                $request->validated(),
                $request->file('image'),
                $request->file('hover_image'),
            ),
        ], 201);
    }

    public function update(UpdateProductRequest $request, int|string $id, ProductManager $products)
    {
        return response()->json([
            'message' => 'Product updated successfully',
            'data' => $products->update(
                $id,
                $request->validated(),
                $request->file('image'),
                $request->file('hover_image'),
            ),
        ]);
    }

    public function destroy(int|string $id, ProductManager $products)
    {
        $products->delete($id);

        return response()->json(['message' => 'Product deleted successfully']);
    }
}
