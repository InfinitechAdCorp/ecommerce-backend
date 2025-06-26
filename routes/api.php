<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\CartController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\TestimonialController;
use App\Http\Controllers\AnalyticsController;
use App\Http\Controllers\ContactController;
use App\Http\Middleware\AdminMiddleware; // Import the AdminMiddleware

// Public routes
Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

// Public contact route (IMPORTANT: This should be outside auth middleware)
Route::post('/contact', [ContactController::class, 'store']);

// Product routes (public)
Route::get('/products', [ProductController::class, 'index']);
Route::get('/products/featured', [ProductController::class, 'featured']);
Route::get('/products/{id}', [ProductController::class, 'show']);

// Testimonial routes (public)
Route::get('/testimonials', [TestimonialController::class, 'index']);
Route::get('/testimonials/{id}', [TestimonialController::class, 'show']);
Route::post('/testimonials', [TestimonialController::class, 'store']);

// Protected routes
Route::middleware('auth:sanctum')->group(function () {
    // Auth routes
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);
    
    // Cart routes
    Route::get('/cart', [CartController::class, 'index']);
    Route::post('/cart', [CartController::class, 'store']);
    Route::put('/cart/{id}', [CartController::class, 'update']);
    Route::delete('/cart/clear', [CartController::class, 'clear']);
    Route::delete('/cart/{id}', [CartController::class, 'destroy']);
    
    // Order routes
    Route::get('/orders', [OrderController::class, 'index']);
    Route::post('/orders', [OrderController::class, 'store']);
    Route::get('/orders/{id}', [OrderController::class, 'show']);
    Route::get('/orders/{id}/track', [OrderController::class, 'trackOrder']);
    
    // Notification routes
    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::get('/notifications/unread-count', [NotificationController::class, 'unreadCount']);
    Route::put('/notifications/{id}/read', [NotificationController::class, 'markAsRead']);
    Route::put('/notifications/mark-all-read', [NotificationController::class, 'markAllAsRead']);
    Route::delete('/notifications/{id}', [NotificationController::class, 'destroy']);
    
    // Image upload route
    Route::post('/upload', [ProductController::class, 'uploadImages']);
    
    // Admin routes
    Route::post('/products', [ProductController::class, 'store']);
    Route::put('/products/{id}', [ProductController::class, 'update']);
    Route::delete('/products/{id}', [ProductController::class, 'destroy']);
    Route::put('/orders/{id}/status', [OrderController::class, 'updateStatus']);
    Route::get('/admin/orders', [OrderController::class, 'adminIndex']);
    Route::get('/admin/orders/{id}', [OrderController::class, 'adminShow']);
    Route::get('/admin/dashboard', [DashboardController::class, 'adminDashboard']);
    Route::get('/admin/customers', [CustomerController::class, 'adminIndex']);
    
    // Admin testimonial routes
    Route::get('/admin/testimonials', [TestimonialController::class, 'adminIndex']);
    Route::put('/admin/testimonials/{id}/approve', [TestimonialController::class, 'approve']);
    Route::put('/admin/testimonials/{id}/toggle-featured', [TestimonialController::class, 'toggleFeatured']);
    Route::delete('/admin/testimonials/{id}', [TestimonialController::class, 'destroy']);
    
    // Analytics routes
    Route::get('/admin/analytics', [AnalyticsController::class, 'dashboard']);
});

// Admin contact routes (protected)
Route::middleware(['auth:sanctum', AdminMiddleware::class])->group(function () { // Use the imported AdminMiddleware class
    Route::get('/admin/contact-inquiries', [ContactController::class, 'index']);
    Route::get('/admin/contact-inquiries/{id}', [ContactController::class, 'show']);
    Route::put('/admin/contact-inquiries/{id}/status', [ContactController::class, 'updateStatus']);
    Route::post('/admin/contact-inquiries/{id}/reply', [ContactController::class, 'reply']);
    Route::delete('/admin/contact-inquiries/{id}', [ContactController::class, 'destroy']);
});
