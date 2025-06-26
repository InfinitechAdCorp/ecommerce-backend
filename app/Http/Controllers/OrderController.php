<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Notification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;

class OrderController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index(Request $request)
    {
        try {
            $orders = $request->user()->orders()
                ->with(['items.product'])
                ->orderBy('created_at', 'desc')
                ->paginate(10);

            return response()->json([
                'success' => true,
                'data' => $orders
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch orders: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.price' => 'required|numeric|min:0',
            'items.*.color' => 'nullable|string',
            'shipping_info.firstName' => 'required|string|max:255',
            'shipping_info.lastName' => 'required|string|max:255',
            'shipping_info.email' => 'required|email|max:255',
            'shipping_info.phone' => 'required|string|max:20',
            'shipping_info.address' => 'required|string|max:500',
            'shipping_info.city' => 'required|string|max:100',
            'shipping_info.province' => 'required|string|max:100',
            'shipping_info.zipCode' => 'required|string|max:10',
            'payment_method' => 'required|in:cod,card,bank_transfer',
            'subtotal' => 'required|numeric|min:0',
            'shipping_fee' => 'required|numeric|min:0',
            'total' => 'required|numeric|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            DB::beginTransaction();

            // Create the order
            $order = Order::create([
                'user_id' => $request->user()->id,
                'first_name' => $request->shipping_info['firstName'],
                'last_name' => $request->shipping_info['lastName'],
                'email' => $request->shipping_info['email'],
                'phone' => $request->shipping_info['phone'],
                'address' => $request->shipping_info['address'],
                'city' => $request->shipping_info['city'],
                'province' => $request->shipping_info['province'],
                'zip_code' => $request->shipping_info['zipCode'],
                'payment_method' => $request->payment_method,
                'subtotal' => $request->subtotal,
                'shipping_fee' => $request->shipping_fee,
                'total' => $request->total,
            ]);

            // Create order items
            foreach ($request->items as $item) {
                OrderItem::create([
                    'order_id' => $order->id,
                    'product_id' => $item['product_id'],
                    'quantity' => $item['quantity'],
                    'price' => $item['price'],
                    'color' => $item['color'] ?? null,
                    'total' => $item['price'] * $item['quantity'],
                ]);
            }

            // Create notification for order placed
            $this->createNotification(
                $request->user()->id,
                'order',
                'Order Placed Successfully',
                "Your order #{$order->order_number} has been placed and is being processed.",
                [
                    'order_id' => $order->id,
                    'order_number' => $order->order_number,
                    'total' => $order->total
                ]
            );

            DB::commit();

            // Load the order with its items and products
            $order->load(['items.product', 'user']);

            return response()->json([
                'success' => true,
                'data' => $order,
                'message' => 'Order placed successfully'
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to create order: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display the specified resource.
     *
     * @param  \App\Models\Order  $order
     * @return \Illuminate\Http\Response
     */
    public function show(Request $request, $id)
    {
        try {
            $order = $request->user()->orders()
                ->with(['items.product'])
                ->findOrFail($id);

            return response()->json([
                'success' => true,
                'data' => $order
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Order not found'
            ], 404);
        }
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \App\Models\Order  $order
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, Order $order)
    {
        $request->validate([
            'user_id' => 'sometimes|exists:users,id',
            'total_amount' => 'sometimes|numeric',
            'shipping_address' => 'sometimes|string',
            'billing_address' => 'sometimes|string',
            'payment_method' => 'sometimes|string',
            'status' => 'sometimes|in:pending,confirmed,processing,shipped,delivered,cancelled',
        ]);

        $order->update($request->all());

        return response()->json($order);
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  \App\Models\Order  $order
     * @return \Illuminate\Http\Response
     */
    public function destroy(Order $order)
    {
        $order->delete();
        return response()->json(null, 204);
    }

    public function updateStatus(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'status' => 'required|in:pending,confirmed,processing,shipped,delivered,cancelled'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $order = Order::findOrFail($id);
            $oldStatus = $order->status;
            $order->status = $request->status;

            // Set timestamps for status changes
            if ($request->status === 'shipped' && !$order->shipped_at) {
                $order->shipped_at = now();
            } elseif ($request->status === 'delivered' && !$order->delivered_at) {
                $order->delivered_at = now();
            }

            $order->save();

            // Create notification for status change (only if status actually changed)
            if ($oldStatus !== $request->status) {
                $statusMessages = [
                    'confirmed' => 'Your order has been confirmed and is being prepared for shipment.',
                    'processing' => 'Your order is being processed and will be shipped soon.',
                    'shipped' => 'Great news! Your order has been shipped and is on its way to you.',
                    'delivered' => 'Your order has been delivered successfully. Thank you for your purchase!',
                    'cancelled' => 'Your order has been cancelled. If you have any questions, please contact us.'
                ];

                if (isset($statusMessages[$request->status])) {
                    $this->createNotification(
                        $order->user_id,
                        'order_status',
                        'Order Status Updated',
                        $statusMessages[$request->status],
                        [
                            'order_id' => $order->id,
                            'order_number' => $order->order_number,
                            'status' => $request->status,
                            'old_status' => $oldStatus
                        ]
                    );
                }
            }

            return response()->json([
                'success' => true,
                'data' => $order,
                'message' => 'Order status updated successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update order status: ' . $e->getMessage()
            ], 500);
        }
    }

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

            $orders = Order::with(['items.product', 'user'])
                ->orderBy('created_at', 'desc')
                ->paginate(20);

            // Add items count to each order
            $orders->getCollection()->transform(function ($order) {
                $order->items_count = $order->items->count();
                return $order;
            });

            return response()->json([
                'success' => true,
                'data' => $orders
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch orders: ' . $e->getMessage()
            ], 500);
        }
    }

    public function adminShow(Request $request, $id)
    {
        try {
            // Check if user is admin
            if (!$request->user() || $request->user()->role !== 'admin') {
                return response()->json([
                    'success' => false,
                    'message' => 'Admin access required'
                ], 403);
            }

            $order = Order::with(['items.product', 'user'])
                ->findOrFail($id);

            // Transform product image URLs to full URLs
            $order->items->transform(function ($item) {
                if ($item->product && $item->product->images) {
                    // The images are already an array from the Product model accessor
                    $images = $item->product->images;
                    
                    if (is_array($images)) {
                        $transformedImages = [];
                        foreach ($images as $image) {
                            if (!str_starts_with($image, 'http')) {
                                if (str_starts_with($image, '/storage')) {
                                    $transformedImages[] = url($image);
                                } else {
                                    $transformedImages[] = url('/storage/products/' . $image);
                                }
                            } else {
                                $transformedImages[] = $image;
                            }
                        }
                        $item->product->images = $transformedImages;
                    }
                }
            
                // Handle legacy image_url field if it exists
                if ($item->product && $item->product->image_url) {
                    if (!str_starts_with($item->product->image_url, 'http')) {
                        if (str_starts_with($item->product->image_url, '/storage')) {
                            $item->product->image_url = url($item->product->image_url);
                        } else {
                            $item->product->image_url = url('/storage/products/' . $item->product->image_url);
                        }
                    }
                }
            
                return $item;
            });

            return response()->json([
                'success' => true,
                'data' => $order
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Order not found: ' . $e->getMessage()
            ], 404);
        }
    }

    /**
     * Get order tracking information
     */
    public function trackOrder(Request $request, $id)
    {
        try {
            $order = Order::findOrFail($id);
            
            // Basic tracking information
            $trackingInfo = [
                'order_number' => $order->order_number,
                'status' => $order->status,
                'timeline' => [
                    [
                        'status' => 'pending',
                        'title' => 'Order Placed',
                        'description' => 'Your order has been received and is being processed',
                        'date' => $order->created_at,
                        'completed' => true
                    ]
                ]
            ];

            // Add confirmed status if applicable
            if (in_array($order->status, ['confirmed', 'processing', 'shipped', 'delivered'])) {
                $trackingInfo['timeline'][] = [
                    'status' => 'confirmed',
                    'title' => 'Order Confirmed',
                    'description' => 'Your order has been confirmed and is being prepared',
                    'date' => $order->updated_at,
                    'completed' => true
                ];
            }

            // Add processing status if applicable
            if (in_array($order->status, ['processing', 'shipped', 'delivered'])) {
                $trackingInfo['timeline'][] = [
                    'status' => 'processing',
                    'title' => 'Order Processing',
                    'description' => 'Your order is being prepared for shipment',
                    'date' => $order->updated_at,
                    'completed' => true
                ];
            }

            // Add shipped status if applicable
            if (in_array($order->status, ['shipped', 'delivered'])) {
                $trackingInfo['timeline'][] = [
                    'status' => 'shipped',
                    'title' => 'Order Shipped',
                    'description' => 'Your order has been shipped and is on its way',
                    'date' => $order->shipped_at ?? $order->updated_at,
                    'completed' => true,
                    'tracking_number' => $order->tracking_number ?? 'TRK-' . strtoupper(substr($order->order_number, -8))
                ];
            }

            // Add delivered status if applicable
            if ($order->status === 'delivered') {
                $trackingInfo['timeline'][] = [
                    'status' => 'delivered',
                    'title' => 'Order Delivered',
                    'description' => 'Your order has been successfully delivered',
                    'date' => $order->delivered_at ?? $order->updated_at,
                    'completed' => true
                ];
            }

            // Add estimated delivery if not delivered
            if ($order->status !== 'delivered' && $order->status !== 'cancelled') {
                $estimatedDelivery = now()->addDays(3); // Adjust based on your delivery timeframe
                if ($order->status === 'shipped') {
                    $estimatedDelivery = now()->addDays(1);
                }

                $trackingInfo['estimated_delivery'] = $estimatedDelivery->toISOString();
            }

            return response()->json([
                'success' => true,
                'data' => $trackingInfo
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Order not found: ' . $e->getMessage()
            ], 404);
        }
    }

    /**
     * Create notification helper method
     */
    private function createNotification($userId, $type, $title, $message, $data = null)
    {
        try {
            Notification::create([
                'user_id' => $userId,
                'type' => $type,
                'title' => $title,
                'message' => $message,
                'data' => $data
            ]);
        } catch (\Exception $e) {
            // Log the error but don't fail the main operation
            Log::error('Failed to create notification: ' . $e->getMessage());
        }
    }
}
