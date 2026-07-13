<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Cart;
use App\Models\Product;

class OrderService
{
    public function checkout($user)
    {
        $cart = Cart::where('user_id', $user->id)
            ->with('items.product')
            ->first();

        if (!$cart || $cart->items->isEmpty()) {
            throw new \Exception("Cart is empty");
        }

        $total = 0;

        // ❌ بدون Lock
        // ❌ بدون Transaction
        foreach ($cart->items as $item) {
            $product = $item->product;

            // ❌ Race Condition هنا - لا تحقق من المخزون
            $product->stock -= $item->quantity;
            $product->save();

            $total += $product->price * $item->quantity;
        }

        // إنشاء الطلب
        $order = Order::create([
            'user_id' => $user->id,
            'total_price' => $total,
            'status' => 'pending'
        ]);

        // إضافة العناصر
        foreach ($cart->items as $item) {
            OrderItem::create([
                'order_id' => $order->id,
                'product_id' => $item->product_id,
                'quantity' => $item->quantity,
                'price' => $item->product->price
            ]);
        }

        // حذف السلة
        $cart->items()->delete();

        return $order;
    }
}
