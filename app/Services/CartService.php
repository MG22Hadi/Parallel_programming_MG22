<?php

namespace App\Services;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Product;

class CartService
{
    public function getUserCart($user)
    {
        return Cart::firstOrCreate(
            ['user_id' => $user->id]
        );
    }

    public function addToCart($user, $productId, $quantity)
    {
        $cart = $this->getUserCart($user);

        $product = Product::findOrFail($productId);

        // تحقق منطقي بسيط (بدون locks الآن)
        if ($product->stock < $quantity) {
            throw new \Exception("Not enough stock");
        }

        $item = CartItem::where('cart_id', $cart->id)
            ->where('product_id', $productId)
            ->first();

        if ($item) {
            $item->quantity += $quantity;
            $item->save();
        } else {
            CartItem::create([
                'cart_id' => $cart->id,
                'product_id' => $productId,
                'quantity' => $quantity
            ]);
        }

        return $cart->load('items.product');
    }

    public function updateItem($user, $productId, $quantity)
    {
        $cart = $this->getUserCart($user);

        $item = CartItem::where('cart_id', $cart->id)
            ->where('product_id', $productId)
            ->firstOrFail();

        $item->quantity = $quantity;
        $item->save();

        return $cart->load('items.product');
    }

    public function removeItem($user, $productId)
    {
        $cart = $this->getUserCart($user);

        CartItem::where('cart_id', $cart->id)
            ->where('product_id', $productId)
            ->delete();

        return $cart->load('items.product');
    }

    public function getCart($user)
    {
        return $this->getUserCart($user)->load('items.product');
    }
}
