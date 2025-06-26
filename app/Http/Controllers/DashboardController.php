<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;
use App\Models\Order;
use App\Models\User;
use App\Models\Product;
use Illuminate\Support\Facades\DB;

class DashboardController extends BaseController
{
    public function __construct()
    {
        $this->middleware('auth:sanctum');
    }

    public function adminDashboard(Request $request)
    {
        try {
            // Get the authenticated user
            $user = $request->user();
            
            // Check if user exists and is admin
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Authentication required'
                ], 401);
            }

            if ($user->role !== 'admin') {
                return response()->json([
                    'success' => false,
                    'message' => 'Admin access required'
                ], 403);
            }

            // Get total revenue from delivered orders only
            $totalRevenue = Order::where('status', 'delivered')
                ->sum('total');

            // Get total orders count
            $totalOrders = Order::count();

            // Get total customers count (users with role 'customer')
            $totalCustomers = User::where('role', 'customer')->count();

            // Get total products count
            $totalProducts = Product::count();

            // Get recent orders (last 10)
            $recentOrders = Order::select([
                    'id',
                    'order_number',
                    'user_id',
                    'first_name',
                    'last_name',
                    'total',
                    'status',
                    'created_at'
                ])
                ->orderBy('created_at', 'desc')
                ->limit(10)
                ->get()
                ->map(function ($order) {
                    return [
                        'id' => $order->id,
                        'order_number' => $order->order_number,
                        'customer_name' => $order->first_name . ' ' . $order->last_name,
                        'total' => (float) $order->total,
                        'status' => $order->status,
                        'created_at' => $order->created_at->toISOString(),
                    ];
                });

            return response()->json([
                'success' => true,
                'data' => [
                    'totalRevenue' => (float) $totalRevenue,
                    'totalOrders' => $totalOrders,
                    'totalCustomers' => $totalCustomers,
                    'totalProducts' => $totalProducts,
                    'recentOrders' => $recentOrders,
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch dashboard data: ' . $e->getMessage()
            ], 500);
        }
    }
}
