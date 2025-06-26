<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Order;
use App\Models\Product;
use App\Models\Testimonial;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Routing\Controller as BaseController;
use Carbon\Carbon;

class AnalyticsController extends BaseController
{
    public function __construct()
    {
        $this->middleware('auth:sanctum');
    }

    public function dashboard(Request $request)
    {
        try {
            // Check if user is admin
            if (!$request->user() || $request->user()->role !== 'admin') {
                return response()->json([
                    'success' => false,
                    'message' => 'Admin access required'
                ], 403);
            }

            // Get date range (default to last 30 days)
            $startDate = $request->get('start_date', Carbon::now()->subDays(30));
            $endDate = $request->get('end_date', Carbon::now());

            // Overview Statistics
            $totalRevenue = Order::where('status', '!=', 'cancelled')
                ->whereBetween('created_at', [$startDate, $endDate])
                ->sum('total');

            $totalOrders = Order::whereBetween('created_at', [$startDate, $endDate])->count();
            $totalCustomers = User::where('role', 'customer')->count();
            $totalProducts = Product::count();

            // Revenue by month (last 12 months)
            $monthlyRevenue = Order::select(
                DB::raw('YEAR(created_at) as year'),
                DB::raw('MONTH(created_at) as month'),
                DB::raw('SUM(total) as revenue'),
                DB::raw('COUNT(*) as orders')
            )
            ->where('status', '!=', 'cancelled')
            ->where('created_at', '>=', Carbon::now()->subMonths(12))
            ->groupBy('year', 'month')
            ->orderBy('year', 'desc')
            ->orderBy('month', 'desc')
            ->get()
            ->map(function ($item) {
                return [
                    'month' => Carbon::create($item->year, $item->month)->format('M Y'),
                    'revenue' => (float) $item->revenue,
                    'orders' => $item->orders
                ];
            });

            // Top selling products
            $topProducts = DB::table('order_items')
                ->join('products', 'order_items.product_id', '=', 'products.id')
                ->join('orders', 'order_items.order_id', '=', 'orders.id')
                ->where('orders.status', '!=', 'cancelled')
                ->whereBetween('orders.created_at', [$startDate, $endDate])
                ->select(
                    'products.id',
                    'products.name',
                    'products.price',
                    DB::raw('SUM(order_items.quantity) as total_sold'),
                    DB::raw('SUM(order_items.total) as total_revenue')
                )
                ->groupBy('products.id', 'products.name', 'products.price')
                ->orderBy('total_sold', 'desc')
                ->limit(10)
                ->get();

            // Order status distribution
            $orderStatusDistribution = Order::select('status', DB::raw('COUNT(*) as count'))
                ->whereBetween('created_at', [$startDate, $endDate])
                ->groupBy('status')
                ->get()
                ->pluck('count', 'status');

            // Recent orders
            $recentOrders = Order::with(['user', 'items'])
                ->orderBy('created_at', 'desc')
                ->limit(10)
                ->get()
                ->map(function ($order) {
                    return [
                        'id' => $order->id,
                        'order_number' => $order->order_number,
                        'customer_name' => $order->user->name ?? $order->first_name . ' ' . $order->last_name,
                        'total' => $order->total,
                        'status' => $order->status,
                        'items_count' => $order->items->count(),
                        'created_at' => $order->created_at->toISOString()
                    ];
                });

            // Customer growth (last 12 months)
            $customerGrowth = User::select(
                DB::raw('YEAR(created_at) as year'),
                DB::raw('MONTH(created_at) as month'),
                DB::raw('COUNT(*) as new_customers')
            )
            ->where('role', 'customer')
            ->where('created_at', '>=', Carbon::now()->subMonths(12))
            ->groupBy('year', 'month')
            ->orderBy('year', 'desc')
            ->orderBy('month', 'desc')
            ->get()
            ->map(function ($item) {
                return [
                    'month' => Carbon::create($item->year, $item->month)->format('M Y'),
                    'new_customers' => $item->new_customers
                ];
            });

            // Average order value
            $avgOrderValue = Order::where('status', '!=', 'cancelled')
                ->whereBetween('created_at', [$startDate, $endDate])
                ->avg('total');

            // Testimonial stats
            $testimonialStats = [
                'total' => Testimonial::count(),
                'approved' => Testimonial::where('is_approved', true)->count(),
                'pending' => Testimonial::where('is_approved', false)->count(),
                'average_rating' => Testimonial::where('is_approved', true)->avg('rating')
            ];

            return response()->json([
                'success' => true,
                'data' => [
                    'overview' => [
                        'total_revenue' => (float) $totalRevenue,
                        'total_orders' => $totalOrders,
                        'total_customers' => $totalCustomers,
                        'total_products' => $totalProducts,
                        'avg_order_value' => (float) $avgOrderValue
                    ],
                    'monthly_revenue' => $monthlyRevenue,
                    'top_products' => $topProducts,
                    'order_status_distribution' => $orderStatusDistribution,
                    'recent_orders' => $recentOrders,
                    'customer_growth' => $customerGrowth,
                    'testimonial_stats' => $testimonialStats,
                    'date_range' => [
                        'start_date' => $startDate,
                        'end_date' => $endDate
                    ]
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch analytics data: ' . $e->getMessage()
            ], 500);
        }
    }
}
