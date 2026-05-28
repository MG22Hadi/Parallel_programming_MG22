<?php

namespace App\Jobs;

use App\Enums\CompensationStatus;
use App\Enums\CompensationType;
use App\Enums\OrderStatus;
use App\Enums\TransactionPhase;
use App\Models\CompensationJob as CompensationJobModel;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Services\Checkout\OrderStateMachineService;
use App\Services\MetricsService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CompensationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;
    public array $backoff = [1, 2, 5, 10];
    public int $timeout = 120;

    public Order $order;

    public function __construct(Order $order)
    {
        $this->order = $order;
        $this->onQueue(config('queue.queues.orders'));
    }

    public function handle(OrderStateMachineService $stateMachine, MetricsService $metrics): void
    {
        $jobStartedAt = microtime(true);

        Log::warning('Compensation started', [
            'order_id' => $this->order->id,
            'attempts' => $this->attempts(),
        ]);

        $order = Order::with('items')->find($this->order->id);
        if (!$order) {
            return;
        }

        $job = CompensationJobModel::firstOrCreate(
            [
                'order_id' => $order->id,
                'type' => CompensationType::STOCK_RESTORE->value,
            ],
            [
                'status' => CompensationStatus::PENDING->value,
                'attempts' => 0,
                'max_attempts' => 3,
                'payload' => ['order_id' => $order->id],
                'result' => [],
            ]
        );

        if ($job->status === CompensationStatus::SUCCEEDED->value) {
            return;
        }

        if ($job->attempts >= $job->max_attempts) {
            $job->update(['status' => CompensationStatus::FAILED->value]);
            return;
        }

        $job->update([
            'status' => CompensationStatus::IN_PROGRESS->value,
            'attempts' => $job->attempts + 1,
            'next_attempt_at' => now()->addMinutes(5),
        ]);

        $stateMachine->recordEvent($order, 'compensation_started', [
            'compensation_type' => CompensationType::STOCK_RESTORE->value,
        ]);

        $maxAttempts = 3;
        $attempt = 0;

        while ($attempt < $maxAttempts) {
            try {
                DB::transaction(function () use ($order, $job, $stateMachine) {
                    $orderItems = OrderItem::where('order_id', $order->id)
                        ->lockForUpdate()
                        ->get();

                    $productIds = $orderItems->pluck('product_id')->unique()->values();
                    $products = Product::whereIn('id', $productIds)
                        ->lockForUpdate()
                        ->get()
                        ->keyBy('id');

                    foreach ($orderItems as $item) {
                        $product = $products->get($item->product_id);
                        if (!$product) {
                            continue;
                        }

                        $product->stock += $item->quantity;
                        $product->save();
                    }

                    $stateMachine->recordEvent($order, 'compensation_completed', [
                        'restored_items' => $orderItems->count(),
                    ]);

                    $stateMachine->transition(
                        $order,
                        OrderStatus::CANCELLED->value,
                        TransactionPhase::ROLLBACK_REQUIRED->value,
                        ['compensation' => CompensationType::STOCK_RESTORE->value]
                    );

                    $stateMachine->recordEvent($order, 'cancelled', []);

                    $job->update([
                        'status' => CompensationStatus::SUCCEEDED->value,
                        'result' => ['restored_items' => $orderItems->count()],
                    ]);

                    $compensationDuration = round((microtime(true) - $jobStartedAt) * 1000, 2);
                    $metrics->increment('compensation.executions');
                    $metrics->recordTiming('compensation.duration', $compensationDuration);

                    Log::info('Compensation completed', [
                        'order_id' => $order->id,
                        'restored_items' => $orderItems->count(),
                        'duration_ms' => $compensationDuration,
                        'attempts' => $this->attempts(),
                    ]);
                });

                return;
            } catch (QueryException $exception) {
                $attempt++;
                if ($this->isDeadlockException($exception)) {
                    $metrics->increment('deadlock.retries');
                }

                if ($this->isDeadlockException($exception) && $attempt < $maxAttempts) {
                    usleep(100000 * $attempt);
                    continue;
                }

                $compensationDuration = round((microtime(true) - $jobStartedAt) * 1000, 2);
                $metrics->increment('compensation.failure');

                $job->update([
                    'status' => CompensationStatus::FAILED->value,
                    'error_reason' => $exception->getMessage(),
                ]);

                Log::error('Compensation failed', [
                    'order_id' => $order->id,
                    'error' => $exception->getMessage(),
                    'duration_ms' => $compensationDuration,
                    'attempts' => $this->attempts(),
                ]);

                throw $exception;
            } catch (\Throwable $exception) {
                $compensationDuration = round((microtime(true) - $jobStartedAt) * 1000, 2);
                $metrics->increment('compensation.failure');

                $job->update([
                    'status' => CompensationStatus::FAILED->value,
                    'error_reason' => $exception->getMessage(),
                ]);

                Log::error('Compensation failed', [
                    'order_id' => $order->id,
                    'error' => $exception->getMessage(),
                    'duration_ms' => $compensationDuration,
                    'attempts' => $this->attempts(),
                ]);

                throw $exception;
            }
        }

        throw new \RuntimeException('Unable to complete compensation after deadlock retries.');
    }

    private function isDeadlockException(QueryException $exception): bool
    {
        $sqlState = $exception->errorInfo[0] ?? $exception->getCode();
        return in_array($sqlState, ['40001', '1213'], true);
    }

    public function tags(): array
    {
        return [
            'compensation',
            'order:' . $this->order->id,
        ];
    }
}
