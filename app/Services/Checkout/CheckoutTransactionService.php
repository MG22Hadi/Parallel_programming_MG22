<?php

namespace App\Services\Checkout;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\TransactionPhase;
use App\Exceptions\InsufficientStockException;
use App\Jobs\PaymentExecutionJob;
use App\Models\Cart;
use App\Models\Order;
use App\Models\PaymentIntent;
use App\Models\Product;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CheckoutTransactionService
{
    public function __construct(private OrderStateMachineService $stateMachine)
    {
    }

    public function execute($user, string $idempotencyKey, array $fingerprint): Order
    {
        $metrics = app(\App\Services\MetricsService::class);
        $checkoutStartedAt = microtime(true);

        $metrics->increment('checkout.total');

        Log::info('Checkout started', [
            'user_id' => $user->id,
            'idempotency_key' => $idempotencyKey,
            'fingerprint' => $fingerprint,
        ]);

        $maxAttempts = 3;
        $attempt = 0;

        while ($attempt < $maxAttempts) {
            try {
                $transactionStartedAt = microtime(true);

                $order = DB::transaction(function () use ($user, $idempotencyKey) {
                    $cart = Cart::where('user_id', $user->id)
                        ->lockForUpdate()
                        ->first();

                    if (!$cart || $cart->items()->count() === 0) {
                        throw new InsufficientStockException('Cart is empty or does not exist.');
                    }

                    $cart->load(['items' => function ($query) {
                        $query->lockForUpdate();
                    }]);

                    $productIds = $cart->items->pluck('product_id')->sort()->values();
                    $products = Product::whereIn('id', $productIds)
                        ->orderBy('id')
                        ->lockForUpdate()
                        ->get()
                        ->keyBy('id');

                    $total = 0;
                    $order = Order::create([
                        'user_id' => $user->id,
                        'total_price' => 0,
                        'currency' => 'USD',
                        'status' => OrderStatus::CHECKOUT_STARTED->value,
                        'transaction_phase' => TransactionPhase::CHECKOUT_STARTED->value,
                        'idempotency_key' => $idempotencyKey,
                    ]);

                    $this->stateMachine->recordEvent($order, 'checkout_started', [
                        'idempotency_key' => $idempotencyKey,
                    ]);

                    foreach ($cart->items as $item) {
                        $product = $products->get($item->product_id);

                        if (!$product) {
                            throw new InsufficientStockException("Product {$item->product_id} was not found.");
                        }

                        if ($product->stock < $item->quantity) {
                            throw new InsufficientStockException("Insufficient stock for product {$product->name}");
                        }

                        $product->stock -= $item->quantity;
                        $product->save();

                        $subtotal = $product->price * $item->quantity;
                        $total += $subtotal;

                        $order->items()->create([
                            'product_id' => $product->id,
                            'product_name_snapshot' => $product->name,
                            'price' => $product->price,
                            'quantity' => $item->quantity,
                            'subtotal' => $subtotal,
                        ]);
                    }

                    $order = $this->stateMachine->transition(
                        $order,
                        OrderStatus::STOCK_RESERVED->value,
                        TransactionPhase::STOCK_RESERVED->value,
                        ['idempotency_key' => $idempotencyKey]
                    );

                    $cart->items()->delete();

                    $paymentIntent = PaymentIntent::create([
                        'order_id' => $order->id,
                        'provider' => 'fake_gateway',
                        'amount' => $total,
                        'currency' => $order->currency,
                        'status' => PaymentStatus::PENDING->value,
                        'idempotency_key' => $idempotencyKey,
                        'attempts' => 0,
                        'raw_response' => [],
                    ]);

                    $order->update([
                        'payment_intent_id' => $paymentIntent->id,
                        'total_price' => $total,
                    ]);

                    $order = $this->stateMachine->transition(
                        $order,
                        OrderStatus::PAYMENT_PENDING->value,
                        TransactionPhase::PAYMENT_PENDING->value,
                        ['payment_intent_id' => $paymentIntent->id]
                    );

                    return $order;
                });

                $order->refresh();

                if ($order->payment_intent_id) {
                    $paymentIntent = PaymentIntent::find($order->payment_intent_id);
                    if ($paymentIntent) {
                        PaymentExecutionJob::dispatch($paymentIntent)
                            ->onQueue(config('queue.queues.orders'));
                    }
                }

                $checkoutDuration = round((microtime(true) - $checkoutStartedAt) * 1000, 2);
                $transactionDuration = round((microtime(true) - $transactionStartedAt) * 1000, 2);

                $metrics->increment('checkout.success');
                $metrics->recordTiming('checkout.duration', $checkoutDuration);

                Log::info('Checkout completed', [
                    'order_id' => $order->id,
                    'payment_intent_id' => $order->payment_intent_id,
                    'idempotency_key' => $idempotencyKey,
                    'checkout_duration_ms' => $checkoutDuration,
                    'db_transaction_duration_ms' => $transactionDuration,
                    'deadlock_retry_count' => $attempt,
                ]);

                return $order;
            } catch (QueryException $e) {
                $attempt++;

                if ($this->isDeadlockException($e)) {
                    $metrics->increment('deadlock.retries');
                }

                if ($this->isDeadlockException($e) && $attempt < $maxAttempts) {
                    usleep(100000 * $attempt);
                    continue;
                }

                $metrics->increment('checkout.failure');
                throw $e;
            }
        }

        throw new \RuntimeException('Unable to complete checkout after deadlock retries.');
    }

    private function isDeadlockException(QueryException $exception): bool
    {
        $sqlState = $exception->errorInfo[0] ?? $exception->getCode();
        return in_array($sqlState, ['40001', '1213'], true);
    }
}
