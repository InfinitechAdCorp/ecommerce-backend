<?php

namespace App\Http\Controllers;

use App\Models\Testimonial;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;

class TestimonialController extends Controller
{
    public function index(Request $request)
    {
        try {
            $query = Testimonial::with(['user', 'product'])
                ->approved()
                ->orderBy('created_at', 'desc');

            // Filter by featured
            if ($request->has('featured') && $request->boolean('featured')) {
                $query->featured();
            }

            // Filter by product
            if ($request->has('product_id') && $request->product_id) {
                $query->where('product_id', $request->product_id);
            }

            // Filter by rating
            if ($request->has('min_rating') && $request->min_rating) {
                $query->where('rating', '>=', $request->min_rating);
            }

            $testimonials = $query->paginate(12);

            return response()->json([
                'success' => true,
                'data' => $testimonials
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch testimonials: ' . $e->getMessage()
            ], 500);
        }
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255',
            'location' => 'required|string|max:255',
            'rating' => 'required|integer|min:1|max:5',
            'title' => 'required|string|max:255',
            'message' => 'required|string|max:1000',
            'product_id' => 'nullable|exists:products,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $data = $request->all();
            
            // Add user_id if authenticated
            if ($request->user()) {
                $data['user_id'] = $request->user()->id;
            }

            // Set default approval status (false for moderation)
            $data['is_approved'] = false;
            $data['is_featured'] = false;

            $testimonial = Testimonial::create($data);

            return response()->json([
                'success' => true,
                'data' => $testimonial,
                'message' => 'Thank you for your testimonial! It will be reviewed and published soon.'
            ], 201);
        } catch (\Exception $e) {
            Log::error('Testimonial creation error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to submit testimonial'
            ], 500);
        }
    }

    public function show($id)
    {
        try {
            $testimonial = Testimonial::with(['user', 'product'])
                ->approved()
                ->findOrFail($id);

            return response()->json([
                'success' => true,
                'data' => $testimonial
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Testimonial not found'
            ], 404);
        }
    }

    // Admin methods
    public function adminIndex(Request $request)
    {
        try {
            // Check if user is admin
            if (!$request->user() || $request->user()->role !== 'admin') {
                return response()->json([
                    'success' => false,
                    'message' => 'Admin access required'
                ], 403);
            }

            $query = Testimonial::with(['user', 'product'])
                ->orderBy('created_at', 'desc');

            // Filter by approval status
            if ($request->has('approved') && $request->approved !== null) {
                $query->where('is_approved', $request->boolean('approved'));
            }

            $testimonials = $query->paginate(20);

            return response()->json([
                'success' => true,
                'data' => $testimonials
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch testimonials: ' . $e->getMessage()
            ], 500);
        }
    }

    public function approve(Request $request, $id)
    {
        try {
            // Check if user is admin
            if (!$request->user() || $request->user()->role !== 'admin') {
                return response()->json([
                    'success' => false,
                    'message' => 'Admin access required'
                ], 403);
            }

            $testimonial = Testimonial::findOrFail($id);
            $testimonial->is_approved = true;
            $testimonial->save();

            return response()->json([
                'success' => true,
                'data' => $testimonial,
                'message' => 'Testimonial approved successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to approve testimonial: ' . $e->getMessage()
            ], 500);
        }
    }

    public function toggleFeatured(Request $request, $id)
    {
        try {
            // Check if user is admin
            if (!$request->user() || $request->user()->role !== 'admin') {
                return response()->json([
                    'success' => false,
                    'message' => 'Admin access required'
                ], 403);
            }

            $testimonial = Testimonial::findOrFail($id);
            $testimonial->is_featured = !$testimonial->is_featured;
            $testimonial->save();

            return response()->json([
                'success' => true,
                'data' => $testimonial,
                'message' => 'Testimonial featured status updated'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update testimonial: ' . $e->getMessage()
            ], 500);
        }
    }

    public function destroy(Request $request, $id)
    {
        try {
            // Check if user is admin
            if (!$request->user() || $request->user()->role !== 'admin') {
                return response()->json([
                    'success' => false,
                    'message' => 'Admin access required'
                ], 403);
            }

            $testimonial = Testimonial::findOrFail($id);
            $testimonial->delete();

            return response()->json([
                'success' => true,
                'message' => 'Testimonial deleted successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete testimonial: ' . $e->getMessage()
            ], 500);
        }
    }
}
