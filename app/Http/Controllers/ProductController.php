<?php

namespace App\Http\Controllers;

use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;

class ProductController extends Controller
{
    public function index(Request $request)
    {
        $query = Product::query();

        // Filter by category
        if ($request->has('category') && $request->category) {
            $query->where('category', $request->category);
        }

        // Enhanced search functionality - search across multiple fields
        if ($request->has('search') && $request->search) {
            $searchTerm = $request->search;
            $query->where(function ($q) use ($searchTerm) {
                $q->where('name', 'LIKE', "%{$searchTerm}%")
                  ->orWhere('description', 'LIKE', "%{$searchTerm}%")
                  ->orWhere('model', 'LIKE', "%{$searchTerm}%")
                  ->orWhere('category', 'LIKE', "%{$searchTerm}%")
                  ->orWhereJsonContains('ideal_for', $searchTerm)
                  ->orWhereJsonContains('colors', $searchTerm);
            });
        }

        // Filter by price range
        if ($request->has('min_price') && $request->min_price) {
            $query->where('price', '>=', $request->min_price);
        }

        if ($request->has('max_price') && $request->max_price) {
            $query->where('price', '<=', $request->max_price);
        }

        // Filter by stock status
        if ($request->has('in_stock') && $request->in_stock !== null) {
            $query->where('in_stock', $request->boolean('in_stock'));
        }

        // Sort options
        $sortBy = $request->get('sort_by', 'created_at');
        $sortOrder = $request->get('sort_order', 'desc');
        
        $allowedSortFields = ['created_at', 'name', 'price', 'updated_at'];
        if (in_array($sortBy, $allowedSortFields)) {
            $query->orderBy($sortBy, $sortOrder);
        }

        $products = $query->get();

        return response()->json([
            'success' => true,
            'data' => $products,
            'total' => $products->count()
        ]);
    }

    // New method for featured products
    public function featured()
    {
        try {
            $featuredProducts = Product::where('featured', true)
                ->where('in_stock', true)
                ->orderBy('created_at', 'desc')
                ->get();

            return response()->json([
                'success' => true,
                'data' => $featuredProducts,
                'total' => $featuredProducts->count()
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch featured products: ' . $e->getMessage()
            ], 500);
        }
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'description' => 'required|string',
            'price' => 'required|numeric|min:0',
            'original_price' => 'nullable|numeric|min:0',
            'category' => 'required|string',
            'model' => 'required|string',
            'specifications' => 'nullable|json',
            'ideal_for' => 'nullable|json',
            'colors' => 'nullable|json',
            'in_stock' => 'nullable|boolean',
            'featured' => 'nullable|boolean',
            'images.*' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
            'existing_images' => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $data = $request->all();
        
        // Handle image uploads
        $imagePaths = [];
        
        // Add existing images
        if ($request->has('existing_images') && is_array($request->existing_images)) {
            $imagePaths = array_merge($imagePaths, $request->existing_images);
        }

        // Upload new images
        if ($request->hasFile('images')) {
            foreach ($request->file('images') as $image) {
                $filename = time() . '_' . Str::random(10) . '.' . $image->getClientOriginalExtension();
                $path = $image->storeAs('products', $filename, 'public');
                $imagePaths[] = asset('storage/' . $path);
            }
        }

        $data['images'] = $imagePaths;
        
        // Decode JSON fields with null fallback
        if (isset($data['specifications']) && $data['specifications']) {
            $data['specifications'] = json_decode($data['specifications'], true);
        } else {
            $data['specifications'] = null;
        }
        
        if (isset($data['ideal_for']) && $data['ideal_for']) {
            $data['ideal_for'] = json_decode($data['ideal_for'], true);
        } else {
            $data['ideal_for'] = null;
        }
        
        if (isset($data['colors']) && $data['colors']) {
            $data['colors'] = json_decode($data['colors'], true);
        } else {
            $data['colors'] = null;
        }

        // Set default boolean values
        $data['in_stock'] = $data['in_stock'] ?? true;
        $data['featured'] = $data['featured'] ?? false;

        $product = Product::create($data);

        return response()->json([
            'success' => true,
            'data' => $product,
            'message' => 'Product created successfully'
        ], 201);
    }

    public function show($id)
    {
        $product = Product::find($id);

        if (!$product) {
            return response()->json([
                'success' => false,
                'message' => 'Product not found'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $product
        ]);
    }

    public function update(Request $request, $id)
    {
        Log::info('Product update request received', [
            'id' => $id,
            'method' => $request->method(),
            'has_files' => $request->hasFile('images'),
            'existing_images' => $request->get('existing_images')
        ]);

        $product = Product::find($id);

        if (!$product) {
            return response()->json([
                'success' => false,
                'message' => 'Product not found'
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'description' => 'required|string',
            'price' => 'required|numeric|min:0',
            'original_price' => 'nullable|numeric|min:0',
            'category' => 'required|string',
            'model' => 'required|string',
            'specifications' => 'nullable|json',
            'ideal_for' => 'nullable|json',
            'colors' => 'nullable|json',
            'in_stock' => 'nullable|boolean',
            'featured' => 'nullable|boolean',
            'images.*' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
            'existing_images' => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $data = $request->all();
        
        // Handle image uploads - IMPROVED LOGIC
        $imagePaths = [];
        
        // First, preserve existing images if they exist
        if ($request->has('existing_images') && is_array($request->existing_images)) {
            $imagePaths = $request->existing_images;
            Log::info('Preserving existing images', ['existing_images' => $imagePaths]);
        } else {
            // If no existing_images provided, keep the current product images
            $imagePaths = $product->images ?? [];
            Log::info('No existing_images provided, keeping current images', ['current_images' => $imagePaths]);
        }

        // Upload new images and add them to the list
        if ($request->hasFile('images')) {
            Log::info('Processing new image uploads', ['count' => count($request->file('images'))]);
            foreach ($request->file('images') as $image) {
                $filename = time() . '_' . Str::random(10) . '.' . $image->getClientOriginalExtension();
                $path = $image->storeAs('products', $filename, 'public');
                $fullUrl = asset('storage/' . $path);
                $imagePaths[] = $fullUrl;
                Log::info('New image uploaded', ['path' => $fullUrl]);
            }
        }

        $data['images'] = $imagePaths;
        Log::info('Final images array', ['images' => $imagePaths]);
        
        // Decode JSON fields with null fallback
        if (isset($data['specifications']) && $data['specifications']) {
            $data['specifications'] = json_decode($data['specifications'], true);
        }
        
        if (isset($data['ideal_for']) && $data['ideal_for']) {
            $data['ideal_for'] = json_decode($data['ideal_for'], true);
        }
        
        if (isset($data['colors']) && $data['colors']) {
            $data['colors'] = json_decode($data['colors'], true);
        }

        // Convert boolean strings to actual booleans
        if (isset($data['in_stock'])) {
            $data['in_stock'] = filter_var($data['in_stock'], FILTER_VALIDATE_BOOLEAN);
        }
        
        if (isset($data['featured'])) {
            $data['featured'] = filter_var($data['featured'], FILTER_VALIDATE_BOOLEAN);
        }

        $product->update($data);

        return response()->json([
            'success' => true,
            'data' => $product->fresh(), // Get fresh data from database
            'message' => 'Product updated successfully'
        ]);
    }

    public function destroy($id)
    {
        $product = Product::find($id);

        if (!$product) {
            return response()->json([
                'success' => false,
                'message' => 'Product not found'
            ], 404);
        }

        // Delete associated images
        if ($product->images) {
            foreach ($product->images as $image) {
                if (!str_starts_with($image, 'http')) {
                    Storage::disk('public')->delete($image);
                }
            }
        }

        $product->delete();

        return response()->json([
            'success' => true,
            'message' => 'Product deleted successfully'
        ]);
    }

    public function uploadImages(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'images.*' => 'required|image|mimes:jpeg,png,jpg,gif|max:2048',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $imagePaths = [];

        if ($request->hasFile('images')) {
            foreach ($request->file('images') as $image) {
                $filename = time() . '_' . Str::random(10) . '.' . $image->getClientOriginalExtension();
                $path = $image->storeAs('products', $filename, 'public');
                $imagePaths[] = asset('storage/' . $path);
            }
        }

        return response()->json([
            'success' => true,
            'urls' => $imagePaths,
            'message' => 'Images uploaded successfully'
        ]);
    }
}
