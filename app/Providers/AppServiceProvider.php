<?php

namespace App\Providers;

use App\Services\MetricsService;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('checkout', function (Request $request) {
            return Limit::perMinute(5)->by(
                $request->user()?->id ?: $request->ip()
            );
        });

        Event::listen(JobProcessing::class, function (JobProcessing $event) {
            $jobId = $this->getJobIdentifier($event->job);
            Cache::store('redis')->put("queue.processing_start:{$jobId}", now()->timestamp, 300);
        });

        Event::listen(JobProcessed::class, function (JobProcessed $event) {
            $metrics = app(MetricsService::class);
            $jobId = $this->getJobIdentifier($event->job);
            $startedAt = Cache::store('redis')->pull("queue.processing_start:{$jobId}");

            if ($startedAt) {
                $durationMs = max(0, (now()->timestamp - $startedAt) * 1000);
                $metrics->recordTiming('queue.processing_duration', $durationMs);
            }

            $metrics->increment('queue.processed_total');
            $metrics->recordRate('queue.processed');
        });

        Event::listen(JobFailed::class, function (JobFailed $event) {
            $metrics = app(MetricsService::class);
            $metrics->increment('queue.failed_total');
            $metrics->recordRate('queue.failed');
        });
    }

    private function getJobIdentifier($job): string
    {
        if (method_exists($job, 'getJobId')) {
            return (string) $job->getJobId();
        }

        return spl_object_id($job);
    }
}
