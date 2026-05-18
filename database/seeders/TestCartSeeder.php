<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use App\Models\Product;
use App\Models\Cart;
use App\Models\CartItem;

class TestCartSeeder extends Seeder
{
    public function run(): void
    {
        // Ensure test user with ID 1 exists
        $user = User::find(1);

        if (!$user) {
            $user = User::create([
                'name' => 'Load Test User',
                'email' => 'loadtest@example.com',
                'password' => bcrypt('password'),
            ]);
        }

        // Create a product
        $product = Product::create([
            'name' => 'Load Test Product',
            'price' => 10.00,
            'stock' => 1000,
        ]);

        // Create or get cart for the user
        $cart = Cart::firstOrCreate([
            'user_id' => $user->id,
        ]);

        // Create a cart item for the product
        CartItem::create([
            'cart_id' => $cart->id,
            'product_id' => $product->id,
            'quantity' => 1,
        ]);
    }
}
