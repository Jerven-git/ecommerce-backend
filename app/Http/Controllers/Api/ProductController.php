<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Http\Request;
use App\Modules\Media\MediaService;
use Illuminate\Support\Facades\Storage;

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
            $childIds = Category::where('parent_id', $categoryId)->pluck('id')->toArray();
            $allIds = array_merge([$categoryId], $childIds);

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

    public function show($id)
    {
        $product = Product::findOrFail($id);
        return response()->json(['data' => $product]);
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
            'is_active' => 'nullable|boolean',
        ]);

        unset($validated['image']);
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
            'stock' => 'nullable|integer|min:0',
            'weight' => 'nullable|numeric|min:0',
            'length_cm' => 'nullable|numeric|min:0',
            'width_cm' => 'nullable|numeric|min:0',
            'height_cm' => 'nullable|numeric|min:0',
            'shipping_calc_type' => 'nullable|in:weight,dimensions',
            'category' => 'nullable|string',
            'is_active' => 'nullable|boolean',
        ]);

        unset($validated['image']);
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