<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Http\Request;
use App\Modules\Media\MediaService;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ProductController extends Controller
{
    public function __construct(private MediaService $mediaService) {}
    
    public function index(Request $request)
    {
        $query = Product::query();

        // Filter by active status
        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        // Filter by category_id (includes subcategories when parent is selected)
        if ($request->has('category_id')) {
            $categoryId = (int) $request->category_id;
            $category = Category::with('childrenRecursive')->find($categoryId);
            $allIds = $category ? array_merge([$categoryId], $category->allDescendantIds()) : [$categoryId];

            // Match by category_id OR legacy category string name
            $categoryNames = Category::whereIn('id', $allIds)->pluck('name')->toArray();
            $query->where(function ($q) use ($allIds, $categoryNames) {
                $q->whereIn('category_id', $allIds)
                  ->orWhereIn('category', $categoryNames);
            });
        } elseif ($request->has('category')) {
            $query->where('category', $request->category);
        }

        // Search by name
        if ($request->has('search')) {
            $query->where('name', 'LIKE', '%' . $request->search . '%');
        }

        // Sorting
        $sort = $request->input('sort', 'created_at');
        $order = $request->input('order', 'desc');
        $query->orderBy($sort, $order);

        // Pagination or limit
        if ($request->has('limit')) {
            $products = $query->limit($request->limit)->get();
            return response()->json(['data' => $products]);
        }

        $products = $query->paginate($request->input('per_page', 15));
        return response()->json($products);
    }

    public function show($slug)
    {
        $product = Product::with('media')->where('slug', $slug)->firstOrFail();
        return response()->json(['data' => $product]);
    }

    public function uploadImages(Request $request, $id)
    {
        $product = Product::findOrFail($id);

        $request->validate([
            'images' => 'required|array|min:1',
            'images.*' => 'image|max:2048',
        ]);

        $uploaded = [];
        foreach ($request->file('images') as $file) {
            $media = $this->mediaService->addToCollection($file, $product, 'gallery', 'products');
            $uploaded[] = $media;
        }

        // Set image_url from first gallery image if not already set
        if (!$product->image_url && count($uploaded)) {
            $product->update(['image_url' => Storage::disk('public')->url($uploaded[0]->path)]);
        }

        return response()->json([
            'message' => count($uploaded) . ' image(s) uploaded',
            'data' => $product->fresh()->load('media'),
        ]);
    }

    public function deleteImage($id, $mediaId)
    {
        $product = Product::findOrFail($id);
        $media = $product->media()->where('id', $mediaId)->firstOrFail();

        Storage::disk('public')->delete($media->path);
        $media->delete();

        // If the deleted image was the main image_url, update to next gallery image or null
        if ($product->image_url && str_contains($product->image_url, basename($media->path))) {
            $next = $product->media()->where('collection', 'gallery')->first();
            $product->update(['image_url' => $next ? Storage::disk('public')->url($next->path) : null]);
        }

        return response()->json([
            'message' => 'Image deleted',
            'data' => $product->fresh()->load('media'),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'price' => 'required|numeric|min:0',
            'image' => 'nullable|image|max:2048',
            'stock' => 'nullable|integer|min:0',
            'weight' => 'nullable|numeric|min:0',
            'length_cm' => 'nullable|numeric|min:0',
            'width_cm' => 'nullable|numeric|min:0',
            'height_cm' => 'nullable|numeric|min:0',
            'shipping_calc_type' => 'nullable|in:weight,dimensions',
            'category' => 'nullable|string',
            'category_id' => 'nullable|exists:categories,id',
            'is_active' => 'nullable|boolean',
            'allow_backorder' => 'nullable|boolean',
            'backorder_charge_policy' => 'nullable|in:charged_now,charged_later',
        ]);

        unset($validated['image']);
        $validated['slug'] = Product::generateUniqueSlug($validated['name']);
        $product = Product::create($validated);

        if ($request->hasFile('image')) {
            $media = $this->mediaService->upload($request->file('image'), $product, 'image', 'products');
            $product->update(['image_url' => Storage::disk('public')->url($media->path)]);
        }

        return response()->json([
            'message' => 'Product created successfully',
            'data' => $product->fresh()
        ], 201);
    }

    public function update(Request $request, $id)
    {
        $product = Product::findOrFail($id);

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'description' => 'nullable|string',
            'price' => 'sometimes|numeric|min:0',
            'image' => 'nullable|image|max:2048',
            // Allow clients to clear a legacy image_url (products seeded with
            // a raw URL but no Media record) by sending image_url=null.
            'image_url' => 'nullable|string',
            'stock' => 'nullable|integer|min:0',
            'weight' => 'nullable|numeric|min:0',
            'length_cm' => 'nullable|numeric|min:0',
            'width_cm' => 'nullable|numeric|min:0',
            'height_cm' => 'nullable|numeric|min:0',
            'shipping_calc_type' => 'nullable|in:weight,dimensions',
            'category' => 'nullable|string',
            'category_id' => 'nullable|exists:categories,id',
            'is_active' => 'nullable|boolean',
            'allow_backorder' => 'nullable|boolean',
            'backorder_charge_policy' => 'nullable|in:charged_now,charged_later',
        ]);

        unset($validated['image']);
        if (isset($validated['name']) && $validated['name'] !== $product->name) {
            $validated['slug'] = Product::generateUniqueSlug($validated['name'], $product->id);
        }
        $product->update($validated);

        if ($request->hasFile('image')) {
            $media = $this->mediaService->upload($request->file('image'), $product, 'image', 'products');
            $product->update(['image_url' => Storage::disk('public')->url($media->path)]);
        }

        return response()->json([
            'message' => 'Product updated successfully',
            'data' => $product->fresh()
        ]);
    }

    public function destroy($id)
    {
        $product = Product::findOrFail($id);
        $product->delete();

        return response()->json([
            'message' => 'Product deleted successfully'
        ]);
    }
}