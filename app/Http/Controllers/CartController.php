<?php

namespace App\Http\Controllers;

use App\Models\Cart;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;

class CartController extends Controller
{
    public function index(Request $request)
    {
        try {
            $cartItems = Cart::where('user_id', $request->user()->id)
                ->with('product')
                ->get()
                ->map(function ($item) {
                    $item->total = $item->price * $item->quantity;
                    return $item;
                });

            return response()->json([
                'success' => true,
                'data' => $cartItems
            ]);
        } catch (\Exception $e) {
            Log::error('Cart index error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch cart items'
            ], 500);
        }
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'product_id' => 'required|exists:products,id',
            'quantity' => 'required|integer|min:1',
            'color' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $product = Product::findOrFail($request->product_id);

            if (!$product->in_stock) {
                return response()->json([
                    'success' => false,
                    'message' => 'Product is out of stock'
                ], 400);
            }

            $existingCartItem = Cart::where('user_id', $request->user()->id)
                ->where('product_id', $request->product_id)
                ->where('color', $request->color)
                ->first();

            if ($existingCartItem) {
                $existingCartItem->quantity += $request->quantity;
                $existingCartItem->total = $existingCartItem->price * $existingCartItem->quantity;
                $existingCartItem->save();
                $cartItem = $existingCartItem;
            } else {
                $cartItem = Cart::create([
                    'user_id' => $request->user()->id,
                    'product_id' => $request->product_id,
                    'quantity' => $request->quantity,
                    'color' => $request->color,
                    'price' => $product->price,
                    'total' => $product->price * $request->quantity,
                ]);
            }

            $cartItem->load('product');

            return response()->json([
                'success' => true,
                'data' => $cartItem,
                'message' => 'Product added to cart successfully'
            ], 201);
        } catch (\Exception $e) {
            Log::error('Cart store error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to add product to cart'
            ], 500);
        }
    }

    public function update(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'quantity' => 'required|integer|min:1',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $cartItem = Cart::where('user_id', $request->user()->id)
                ->where('id', $id)
                ->firstOrFail();

            $cartItem->update([
                'quantity' => $request->quantity,
                'total' => $cartItem->price * $request->quantity,
            ]);

            $cartItem->load('product');

            return response()->json([
                'success' => true,
                'data' => $cartItem,
                'message' => 'Cart updated successfully'
            ]);
        } catch (\Exception $e) {
            Log::error('Cart update error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to update cart item'
            ], 500);
        }
    }

    public function destroy(Request $request, $id)
    {
        try {
            $cartItem = Cart::where('user_id', $request->user()->id)
                ->where('id', $id)
                ->firstOrFail();

            $cartItem->delete();

            return response()->json([
                'success' => true,
                'message' => 'Item removed from cart successfully'
            ]);
        } catch (\Exception $e) {
            Log::error('Cart destroy error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to remove cart item'
            ], 500);
        }
    }

    public function clear(Request $request)
    {
        try {
            $user = $request->user();
            
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'User not authenticated'
                ], 401);
            }

            Log::info('Clearing cart for user: ' . $user->id);

            // Get cart items count before deletion for logging
            $cartItemsCount = Cart::where('user_id', $user->id)->count();
            Log::info('Found ' . $cartItemsCount . ' cart items to delete');

            if ($cartItemsCount === 0) {
                return response()->json([
                    'success' => true,
                    'message' => 'Cart is already empty'
                ]);
            }

            // Delete all cart items for the user
            $deletedCount = Cart::where('user_id', $user->id)->delete();
            
            Log::info('Deleted ' . $deletedCount . ' cart items');

            return response()->json([
                'success' => true,
                'message' => 'Cart cleared successfully',
                'deleted_items' => $deletedCount
            ]);

        } catch (\Exception $e) {
            Log::error('Cart clear error: ' . $e->getMessage());
            Log::error('Stack trace: ' . $e->getTraceAsString());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to clear cart: ' . $e->getMessage()
            ], 500);
        }
    }
}
