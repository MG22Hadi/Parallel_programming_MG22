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
Route::get('/products/{product}', [ProductController::class, 'show']); // Public product view

// ========== Test Route for Race Condition (Temporary) ==========
Route::post('/test-stock-race', function () {
    $product = \App\Models\Product::find(1);

    if (!$product) {
        return response()->json([
            'message' => 'Product not found'
        ], 404);
    }

    // محاكاة delay حقيقي لفرض التداخل (Interleaving)
    usleep(2000000); // 2 seconds

    // التحقق بعد الانتظار للسماح بـ race condition
    if ($product->stock < 1) {
        return response()->json([
            'message' => 'Out of stock',
            'stock' => $product->stock
        ], 400);
    }

    $product->stock -= 1;
    $product->save();

    return response()->json([
        'stock' => $product->stock,
        'message' => 'Stock decremented successfully'
    ]);
});
// ===================================================

// Protected routes (authentication required)
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    
    // Product management (admin)
    Route::post('/products', [ProductController::class, 'store']);
    Route::put('/products/{product}', [ProductController::class, 'update']);
    Route::delete('/products/{product}', [ProductController::class, 'destroy']);
    
    // Cart operations
    Route::post('/cart/add', [CartController::class, 'add']);
    Route::put('/cart/update', [CartController::class, 'update']);
    Route::delete('/cart/remove', [CartController::class, 'remove']);
    Route::get('/cart', [CartController::class, 'index']);
    
    // Checkout
    Route::post('/checkout', [OrderController::class, 'checkout']);
});
