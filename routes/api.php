<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CartController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\OrderController;

// Public routes (no authentication required)
Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);
Route::get('/products', [ProductController::class, 'index']); // Public product listing
Route::get('/products/{id}', [ProductController::class, 'show']); // Public product view
Route::post('/buy/{id}', [ProductController::class, 'decrementStockOptimistic']); // Optimistic stock test endpoint

// Protected routes (authentication required)
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    
    // Product management (admin)
    Route::post('/products', [ProductController::class, 'store']);
    Route::put('/products/{product}', [ProductController::class, 'update']);
    Route::post('/products/{id}/decrement-stock', [ProductController::class, 'decrementStockOptimistic']);
    Route::delete('/products/{product}', [ProductController::class, 'destroy']);
    
    // Cart operations
    Route::post('/cart/add', [CartController::class, 'add']);
    Route::put('/cart/update', [CartController::class, 'update']);
    Route::delete('/cart/remove', [CartController::class, 'remove']);
    Route::get('/cart', [CartController::class, 'index']);
    
    // Checkout
    Route::post('/checkout', [OrderController::class, 'checkout'])
    ->middleware('throttle:checkout');
});
