<?php

namespace App\Services;

use App\Models\Product;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ProductOptimisticService
{
    public function decrementStock(int $productId, int $quantity = 1, int $maxRetries = 5): array
    {
        $attempt = 0;

        while ($attempt < $maxRetries) {
            $product = Product::find($productId);

            if (!$product) {
                throw new \Exception('Product not found');
            }

            if ($product->stock < $quantity) {
                throw new \Exception('Out of stock');
            }

            $currentVersion = $product->version;
            $newStock = $product->stock - $quantity;

            $currentAttempt = $attempt + 1;
            Log::info("Optimistic update attempt {$currentAttempt} for product {$productId} version {$currentVersion}");

            $updated = Product::where('id', $productId)
                ->where('version', $currentVersion)
                ->update([
                    'stock' => $newStock,
                    'version' => $currentVersion + 1,
                ]);

            if ($updated) {
                Cache::forget("product:{$productId}");
                Log::info("Update success for product {$productId} after attempt {$currentAttempt}");

                return [
                    'attempts' => $currentAttempt,
                    'product' => Product::find($productId),
                ];
            }

            Log::warning("Version conflict detected for product {$productId} at version {$currentVersion}");
            $attempt++;
            if ($attempt < $maxRetries) {
                Log::info("Retry attempt {$attempt} for product {$productId}");
            }
            usleep(50000);
        }

        throw new \Exception('Concurrent modification detected after ' . $maxRetries . ' retries');
    }
}
