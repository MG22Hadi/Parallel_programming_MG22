<?php

namespace App\Services;

use App\Models\Product;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ProductCacheService
{
    protected int $ttl = 600;

    public function findById(int $id): ?array
    {
        $cacheKey = "product:{$id}";
        $lockKey = "product:{$id}:lock";
        $cache = Cache::store(config('cache.default'));

        $cached = $cache->get($cacheKey);
        if ($cached !== null) {
            Log::info("CACHE HIT {$cacheKey}");
            return $cached;
        }

        Log::info("CACHE MISS {$cacheKey}");

        $lock = $cache->lock($lockKey, 10);

        if ($lock->get()) {
            try {
                Log::info("LOCK ACQUIRED {$lockKey}");

                $product = Product::find($id);
                if (!$product) {
                    return null;
                }

                $payload = $product->toArray();
                $cache->put($cacheKey, $payload, $this->ttl);
                Log::info("CACHE WRITTEN {$cacheKey}");

                return $payload;
            } finally {
                $lock->release();
                Log::info("LOCK RELEASED {$lockKey}");
            }
        }

        Log::info("LOCK WAITING {$lockKey}");
        if ($lock->block(5)) {
            $cachedAfterWait = $cache->get($cacheKey);
            if ($cachedAfterWait !== null) {
                Log::info("CACHE HIT AFTER LOCK WAIT {$cacheKey}");
                return $cachedAfterWait;
            }
        }

        $stale = $cache->get($cacheKey);
        if ($stale !== null) {
            Log::warning("STALE CACHE FALLBACK {$cacheKey}");
            return $stale;
        }

        Log::warning("CACHE LOCK FAILED, DIRECT DB FETCH {$cacheKey}");
        return Product::find($id)?->toArray();
    }
}
