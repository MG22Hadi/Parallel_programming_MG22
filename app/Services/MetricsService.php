<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Carbon;

class MetricsService
{
    private const KEY_PREFIX = 'metrics:';
    private const RATE_KEY_PREFIX = 'metrics:rate:';
    private const RATE_RETENTION_SECONDS = 300;

    public function increment(string $metric, int $amount = 1): int
    {
        return Cache::increment($this->key($metric), $amount) ?: Cache::put($this->key($metric), $amount, now()->addDays(7)) && $amount;
    }

    public function recordTiming(string $metric, float $durationMs): void
    {
        Cache::increment($this->key("{$metric}.count"), 1) ?: Cache::put($this->key("{$metric}.count"), 1, now()->addDays(7));
        Cache::increment($this->key("{$metric}.total_ms"), (int) round($durationMs)) ?: Cache::put($this->key("{$metric}.total_ms"), (int) round($durationMs), now()->addDays(7));
    }

    public function recordRate(string $metric): void
    {
        if (! $redis = $this->redis()) {
            return;
        }

        $key = $this->rateKey($metric);
        $timestamp = now()->timestamp;

        try {
            $redis->zadd($key, [$timestamp => $timestamp]);
            $redis->zremrangebyscore($key, 0, $timestamp - self::RATE_RETENTION_SECONDS);
        } catch (\Throwable $exception) {
            Log::warning('Unable to record rate metric', ['metric' => $metric, 'error' => $exception->getMessage()]);
        }
    }

    public function getRate(string $metric, int $seconds = 60): float
    {
        if (! $redis = $this->redis()) {
            return 0.0;
        }

        $key = $this->rateKey($metric);
        $now = now()->timestamp;
        $start = $now - max($seconds, 1) + 1;

        try {
            $redis->zremrangebyscore($key, 0, $now - self::RATE_RETENTION_SECONDS);
            $count = $redis->zcount($key, $start, $now);
            return $count / max($seconds, 1);
        } catch (\Throwable $exception) {
            Log::warning('Unable to calculate rate metric', ['metric' => $metric, 'error' => $exception->getMessage()]);
            return 0.0;
        }
    }

    public function get(string $metric, int $default = 0): int
    {
        return (int) Cache::get($this->key($metric), $default);
    }

    public function getAverage(string $metric): float
    {
        $count = (int) Cache::get($this->key("{$metric}.count"), 0);
        if ($count === 0) {
            return 0.0;
        }

        $total = (int) Cache::get($this->key("{$metric}.total_ms"), 0);

        return $total / $count;
    }

    public function getMetrics(): array
    {
        return [
            'checkout_total' => $this->get('checkout.total'),
            'checkout_success' => $this->get('checkout.success'),
            'checkout_failure' => $this->get('checkout.failure'),
            'checkout_avg_duration_ms' => round($this->getAverage('checkout.duration'), 2),
            'payment_success' => $this->get('payment.success'),
            'payment_failure' => $this->get('payment.failure'),
            'compensation_executions' => $this->get('compensation.executions'),
            'compensation_failures' => $this->get('compensation.failure'),
            'deadlock_retries' => $this->get('deadlock.retries'),
            'queue_processed_total' => $this->get('queue.processed_total'),
            'queue_failed_total' => $this->get('queue.failed_total'),
            'queue_processed_per_sec' => round($this->getRate('queue.processed'), 2),
            'queue_failed_per_sec' => round($this->getRate('queue.failed'), 2),
            'queue_avg_processing_duration_ms' => round($this->getAverage('queue.processing_duration'), 2),
        ];
    }

    private function key(string $metric): string
    {
        return self::KEY_PREFIX . str_replace('.', ':', $metric);
    }

    private function rateKey(string $metric): string
    {
        return self::RATE_KEY_PREFIX . str_replace('.', ':', $metric);
    }

    private function redis()
    {
        try {
            return Cache::store('redis')->connection();
        } catch (\Throwable $exception) {
            Log::warning('Redis connection unavailable for metrics', ['error' => $exception->getMessage()]);
            return null;
        }
    }
}
