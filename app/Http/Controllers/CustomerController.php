<?php

namespace App\Http\Controllers;

use Illuminate\Routing\Controller as BaseController;
use App\Models\User;
use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CustomerController extends BaseController
{
    public function __construct()
    {
        $this->middleware('auth:sanctum');
    }

    public function adminIndex(Request $request)
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

            // Get all customers with their order statistics
            $customers = User::where('role', 'customer')
                ->select([
                    'id',
                    'name',
                    'email',
                    'role',
                    'created_at',
                    'updated_at'
                ])
                ->withCount('orders') // This adds orders_count
                ->withSum('orders', 'total') // This adds orders_sum_total
                ->orderBy('created_at', 'desc')
                ->get()
                ->map(function ($customer) {
                    return [
                        'id' => $customer->id,
                        'name' => $customer->name,
                        'email' => $customer->email,
                        'role' => $customer->role,
                        'created_at' => $customer->created_at->toISOString(),
                        'updated_at' => $customer->updated_at->toISOString(),
                        'orders_count' => $customer->orders_count ?? 0,
                        'total_spent' => (float) ($customer->orders_sum_total ?? 0),
                        'status' => 'active', // You can add logic for customer status
                    ];
                });

            return response()->json([
                'success' => true,
                'data' => $customers
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch customers: ' . $e->getMessage()
            ], 500);
        }
    }
}