<?php

namespace App\Http\Controllers;

use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;

class ProductController extends Controller
{
    /**
     * Save uploaded image directly to public directory
     * Returns RELATIVE path for direct public access
     */
    private function saveImageToPublic($image)
    {
        try {
            // Create the directory if it doesn't exist
            $publicPath = public_path('images/products');
            if (!File::exists($publicPath)) {
                File::makeDirectory($publicPath, 0755, true);
                Log::info('Created directory: ' . $publicPath);
            }

            // Generate unique filename
            $filename = time() . '_' . Str::random(10) . '.' . $image->getClientOriginalExtension();
            
            // Move file directly to public directory
            $image->move($publicPath, $filename);
            
            // Return ONLY the relative path - NO STORAGE REFERENCES
            $relativePath = '/images/products/' . $filename;
            Log::info('Image saved directly to public', ['path' => $relativePath]);
            
            return $relativePath;
        } catch (\Exception $e) {
            Log::error('Failed to save image to public directory', ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    /**
     * Clean any path to be direct public path
     * NO STORAGE CONVERSION - DIRECT PUBLIC ONLY
     */
    private function cleanImagePath($imagePath)
    {
        if (empty($imagePath)) {
            return $imagePath;
        }

        // If it's already a clean public path, return it
        if (strpos($imagePath, '/images/products/') === 0) {
            return $imagePath;
        }

        // Extract just the filename from any path
        $filename = basename($imagePath);
        
        // Return clean public path
        return '/images/products/' . $filename;
    }

    public function index(Request $request)
    {
        $query = Product::query();

        // Filter by category
        if ($request->has('category') && $request->category) {
            $query->where('category', $request->category);
        }

        // Enhanced search functionality
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

        // Clean all image paths to be direct public paths
        $products->transform(function ($product) {
            if ($product->images && is_array($product->images)) {
                $cleanedImages = array_map([$this, 'cleanImagePath'], $product->images);
                $product->images = $cleanedImages;
                // Update database with clean paths
                $product->save();
            }
            return $product;
        });

        return response()->json([
            'success' => true,
            'data' => $products,
            'total' => $products->count()
        ]);
    }

    public function featured()
    {
        try {
            $featuredProducts = Product::where('featured', true)
                ->where('in_stock', true)
                ->orderBy('created_at', 'desc')
                ->get();

            // Clean all image paths
            $featuredProducts->transform(function ($product) {
                if ($product->images && is_array($product->images)) {
                    $cleanedImages = array_map([$this, 'cleanImagePath'], $product->images);
                    $product->images = $cleanedImages;
                    $product->save();
                }
                return $product;
            });

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
        
        // Handle image uploads - DIRECT TO PUBLIC ONLY
        $imagePaths = [];
        
        // Clean existing images
        if ($request->has('existing_images') && is_array($request->existing_images)) {
            $cleanedExisting = array_map([$this, 'cleanImagePath'], $request->existing_images);
            $imagePaths = array_merge($imagePaths, $cleanedExisting);
        }

        // Upload new images directly to public
        if ($request->hasFile('images')) {
            foreach ($request->file('images') as $image) {
                $imagePath = $this->saveImageToPublic($image);
                $imagePaths[] = $imagePath;
            }
        }

        $data['images'] = $imagePaths;
        
        // Decode JSON fields
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

        // Clean image paths
        if ($product->images && is_array($product->images)) {
            $cleanedImages = array_map([$this, 'cleanImagePath'], $product->images);
            $product->images = $cleanedImages;
            $product->save();
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
        
        // Handle image uploads - DIRECT TO PUBLIC ONLY
        $imagePaths = [];
        
        // Clean existing images
        if ($request->has('existing_images') && is_array($request->existing_images)) {
            $cleanedExisting = array_map([$this, 'cleanImagePath'], $request->existing_images);
            $imagePaths = $cleanedExisting;
            Log::info('Preserving existing images', ['existing_images' => $imagePaths]);
        } else {
            // Clean current product images
            $currentImages = $product->images ?? [];
            $imagePaths = array_map([$this, 'cleanImagePath'], $currentImages);
            Log::info('No existing_images provided, keeping current images', ['current_images' => $imagePaths]);
        }

        // Upload new images directly to public
        if ($request->hasFile('images')) {
            Log::info('Processing new image uploads', ['count' => count($request->file('images'))]);
            foreach ($request->file('images') as $image) {
                $imagePath = $this->saveImageToPublic($image);
                $imagePaths[] = $imagePath;
                Log::info('New image uploaded', ['path' => $imagePath]);
            }
        }

        $data['images'] = $imagePaths;
        Log::info('Final images array', ['images' => $imagePaths]);
        
        // Decode JSON fields
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
            'data' => $product->fresh(),
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

        // Delete images from public directory
        if ($product->images && is_array($product->images)) {
            foreach ($product->images as $imagePath) {
                $cleanPath = $this->cleanImagePath($imagePath);
                $relativePath = ltrim($cleanPath, '/');
                $fullPath = public_path($relativePath);
                
                if (File::exists($fullPath)) {
                    File::delete($fullPath);
                    Log::info('Deleted image file', ['path' => $fullPath]);
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
                // Save directly to public - NO STORAGE
                $imagePath = $this->saveImageToPublic($image);
                $imagePaths[] = $imagePath;
            }
        }

        return response()->json([
            'success' => true,
            'urls' => $imagePaths,
            'message' => 'Images uploaded successfully'
        ]);
    }
}
