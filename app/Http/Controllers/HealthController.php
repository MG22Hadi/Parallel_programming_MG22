<?php

namespace App\Http\Controllers;

use App\Services\MetricsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

class HealthController extends Controller
{
    public function health(): JsonResponse
    {
        try {
            DB::connection()->getPdo();
            $queueDriver = Queue::getDefaultDriver();

            return response()->json([
                'status' => 'ok',
                'database' => 'connected',
                'queue_connection' => $queueDriver,
            ], 200);
        } catch (\Throwable $exception) {
            Log::channel('stress')->error('Health check failed', ['error' => $exception->getMessage()]);

            return response()->json([
                'status' => 'error',
                'message' => 'Health check failed.',
                'details' => $exception->getMessage(),
            ], 500);
        }
    }

    public function queueStatus(): JsonResponse
    {
        try {
            $connection = Queue::getDefaultDriver();
            $queueName = config('queue.connections.' . $connection . '.queue', 'default');
            $pendingCount = DB::table('jobs')
                ->where('queue', $queueName)
                ->count();
            $failedCount = DB::table('failed_jobs')->count();

            return response()->json([
                'status' => 'ok',
                'queue_connection' => $connection,
                'queue_name' => $queueName,
                'pending_jobs' => $pendingCount,
                'failed_jobs' => $failedCount,
            ], 200);
        } catch (\Throwable $exception) {
            Log::channel('stress')->error('Queue status check failed', ['error' => $exception->getMessage()]);

            return response()->json([
                'status' => 'error',
                'message' => 'Queue status check failed.',
                'details' => $exception->getMessage(),
            ], 500);
        }
    }

    public function metrics(MetricsService $metrics): JsonResponse
    {
        return response()->json(array_merge(['status' => 'ok'], $metrics->getMetrics()), 200);
    }

    public function queueHealth(MetricsService $metrics): JsonResponse
    {
        try {
            $pendingCount = DB::table('jobs')->count();
            $failedCount = DB::table('failed_jobs')->count();
            $oldestJob = DB::table('jobs')
                ->select('created_at')
                ->orderBy('created_at')
                ->first();

            $oldestAge = $oldestJob ? now()->timestamp - $oldestJob->created_at : 0;
            $distribution = DB::table('jobs')
                ->select('queue', DB::raw('count(*) as total'))
                ->groupBy('queue')
                ->pluck('total', 'queue')
                ->toArray();

            if ($oldestAge > 60) {
                Log::channel('stress')->warning('Queue lag spike detected', [
                    'pending_jobs' => $pendingCount,
                    'failed_jobs' => $failedCount,
                    'oldest_pending_job_age_seconds' => $oldestAge,
                    'queue_distribution' => $distribution,
                ]);
            }

            return response()->json([
                'status' => 'ok',
                'pending_jobs' => $pendingCount,
                'failed_jobs' => $failedCount,
                'oldest_pending_job_age_seconds' => $oldestAge,
                'queue_distribution' => $distribution,
            ], 200);
        } catch (\Throwable $exception) {
            Log::channel('stress')->error('Queue health check failed', ['error' => $exception->getMessage()]);

            return response()->json([
                'status' => 'error',
                'message' => 'Queue health check failed.',
                'details' => $exception->getMessage(),
            ], 500);
        }
    }

    public function dbHealth(MetricsService $metrics): JsonResponse
    {
        try {
            DB::connection()->getPdo();

            $averageCheckoutDuration = $metrics->getAverage('checkout.duration');
            $slowTransactions = $averageCheckoutDuration > 2000;

            return response()->json([
                'status' => 'ok',
                'database' => 'connected',
                'total_orders' => DB::table('orders')->count(),
                'total_payment_intents' => DB::table('payment_intents')->count(),
                'total_products' => DB::table('products')->count(),
                'slow_checkout_transactions' => $slowTransactions,
                'checkout_avg_duration_ms' => round($averageCheckoutDuration, 2),
            ], 200);
        } catch (\Throwable $exception) {
            Log::channel('stress')->error('DB health check failed', ['error' => $exception->getMessage()]);

            return response()->json([
                'status' => 'error',
                'message' => 'DB health check failed.',
                'details' => $exception->getMessage(),
            ], 500);
        }
    }

    public function systemHealth(): JsonResponse
    {
        return response()->json([
            'status' => 'ok',
            'memory_usage_bytes' => memory_get_usage(true),
            'memory_usage_human' => $this->humanReadableBytes(memory_get_usage(true)),
            'php_version' => PHP_VERSION,
            'laravel_version' => App::version(),
            'queue_driver' => Queue::getDefaultDriver(),
            'cache_driver' => config('cache.default'),
            'timestamp' => now()->toIso8601String(),
        ], 200);
    }

    private function humanReadableBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $power = $bytes > 0 ? (int) floor(log($bytes, 1024)) : 0;

        return round($bytes / (1024 ** $power), 2) . ' ' . $units[$power];
    }
}
