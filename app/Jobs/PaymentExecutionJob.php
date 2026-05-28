<?php

namespace App\Jobs;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\TransactionPhase;
use App\Jobs\CompensationJob as CompensationJobJob;
use App\Jobs\ProcessOrderPostActions;
use App\Models\PaymentIntent;
use App\Services\Checkout\OrderStateMachineService;
use App\Services\MetricsService;
use App\Services\Payments\FakePaymentGateway;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class PaymentExecutionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public array $backoff = [2, 5, 10];
    public int $timeout = 120;

    public PaymentIntent $paymentIntent;

    public function __construct(PaymentIntent $paymentIntent)
    {
        $this->paymentIntent = $paymentIntent;
        $this->onQueue(config('queue.queues.orders'));
    }

    public function handle(FakePaymentGateway $gateway, OrderStateMachineService $stateMachine, MetricsService $metrics): void
    {
        $jobStartedAt = microtime(true);

        $metrics->increment('payment.total');

        Log::info('Payment execution started', [
            'payment_intent_id' => $this->paymentIntent->id,
            'order_id' => $this->paymentIntent->order_id,
            'attempts' => $this->attempts(),
        ]);

        $paymentIntent = PaymentIntent::find($this->paymentIntent->id);

        if (!$paymentIntent || $paymentIntent->status !== PaymentStatus::PENDING->value) {
            return;
        }

        $order = $paymentIntent->order;

        if ($order) {
            $stateMachine->recordEvent($order, 'payment_started', [
                'payment_intent_id' => $paymentIntent->id,
            ]);
        }

        $authorization = $gateway->authorize([
            'amount' => $paymentIntent->amount,
            'currency' => $paymentIntent->currency,
        ]);

        $paymentIntent->increment('attempts');
        $paymentIntent->update([
            'provider_reference' => $authorization['provider_reference'] ?? $paymentIntent->provider_reference,
            'raw_response' => $authorization['raw_response'] ?? [],
        ]);

        if ($authorization['status'] === 'success') {
            if ($order) {
                $stateMachine->recordEvent($order, 'payment_authorized', [
                    'provider_reference' => $authorization['provider_reference'] ?? null,
                ]);
            }

            $capture = $gateway->capture($authorization['provider_reference'] ?? $paymentIntent->provider_reference);
            $paymentIntent->update(['raw_response' => $capture['raw_response'] ?? []]);

            if ($capture['status'] === 'success') {
                $paymentIntent->update(['status' => PaymentStatus::CAPTURED->value]);

                if ($order) {
                    $stateMachine->transition(
                        $order,
                        OrderStatus::PAID->value,
                        TransactionPhase::PAID->value,
                        ['payment_intent_id' => $paymentIntent->id]
                    );

                    $stateMachine->recordEvent($order, 'paid', [
                        'payment_intent_id' => $paymentIntent->id,
                    ]);

                    ProcessOrderPostActions::dispatch($order)
                        ->onQueue(config('queue.queues.orders'));
                }

                $paymentDuration = round((microtime(true) - $jobStartedAt) * 1000, 2);
                $metrics->increment('payment.success');
                $metrics->recordTiming('payment.duration', $paymentDuration);

                Log::info('Payment execution succeeded', [
                    'payment_intent_id' => $paymentIntent->id,
                    'order_id' => $paymentIntent->order_id,
                    'duration_ms' => $paymentDuration,
                    'attempts' => $this->attempts(),
                ]);

                return;
            }
        }

        $paymentIntent->update(['status' => PaymentStatus::FAILED->value]);

        if ($order) {
            $stateMachine->recordEvent($order, 'payment_failed', [
                'payment_intent_id' => $paymentIntent->id,
                'reason' => $authorization['message'] ?? 'payment_failed',
            ]);

            $stateMachine->transition(
                $order,
                OrderStatus::FAILED->value,
                TransactionPhase::ROLLBACK_REQUIRED->value,
                ['reason' => 'payment_failed']
            );

            CompensationJobJob::dispatch($order)
                ->onQueue(config('queue.queues.orders'));

            $paymentDuration = round((microtime(true) - $jobStartedAt) * 1000, 2);
            $metrics->increment('payment.failure');
            $metrics->recordTiming('payment.duration', $paymentDuration);

            Log::warning('Payment execution failed', [
                'payment_intent_id' => $paymentIntent->id,
                'order_id' => $paymentIntent->order_id,
                'reason' => $authorization['message'] ?? 'payment_failed',
                'duration_ms' => $paymentDuration,
                'attempts' => $this->attempts(),
            ]);
        }
    }

    public function tags(): array
    {
        return [
            'payment_execution',
            'payment_intent:' . $this->paymentIntent->id,
            'order:' . $this->paymentIntent->order_id,
        ];
    }
}
