<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Cart;
use App\Models\Product;
use Illuminate\Support\Facades\DB;

class OrderService
{
    public function checkout($user)
    {
        return DB::transaction(function () use ($user) {
            $cart = Cart::where('user_id', $user->id)
                ->lockForUpdate()
                ->with('items.product')
                ->first();

            if (!$cart || $cart->items->isEmpty()) {
                throw new \Exception("Cart is empty");
            }

            $total = 0;
            $orderItems = [];

            foreach ($cart->items as $item) {
                $product = Product::lockForUpdate()->find($item->product_id);

                if (!$product) {
                    throw new \Exception("Product not found");
                }

                if ($product->stock < $item->quantity) {
                    throw new \Exception("Insufficient stock for product ID {$product->id}");
                }

                $product->stock -= $item->quantity;
                $product->save();

                $total += $product->price * $item->quantity;

                $orderItems[] = [
                    'product_id' => $item->product_id,
                    'quantity' => $item->quantity,
                    'price' => $product->price,
                ];
            }

            $order = Order::create([
                'user_id' => $user->id,
                'total_price' => $total,
                'status' => 'pending',
            ]);

            foreach ($orderItems as $orderItem) {
                OrderItem::create([
                    'order_id' => $order->id,
                    'product_id' => $orderItem['product_id'],
                    'quantity' => $orderItem['quantity'],
                    'price' => $orderItem['price'],
                ]);
            }

            $cart->items()->delete();

            return $order;
        });
    }
}
