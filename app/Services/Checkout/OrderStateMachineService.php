<?php

namespace App\Services\Checkout;

use App\Enums\OrderStatus;
use App\Exceptions\InvalidOrderStateException;
use App\Models\Order;
use App\Models\OrderEvent;
use Illuminate\Support\Facades\Log;

class OrderStateMachineService
{
    private const ALLOWED_TRANSITIONS = [
        OrderStatus::CHECKOUT_STARTED->value => [OrderStatus::STOCK_RESERVED->value],
        OrderStatus::STOCK_RESERVED->value => [OrderStatus::PAYMENT_PENDING->value],
        OrderStatus::PAYMENT_PENDING->value => [OrderStatus::PAID->value, OrderStatus::FAILED->value],
        OrderStatus::PAID->value => [OrderStatus::COMPLETED->value],
        OrderStatus::FAILED->value => [OrderStatus::CANCELLED->value],
        OrderStatus::CANCELLED->value => [],
        OrderStatus::COMPLETED->value => [],
    ];

    public function transition(Order $order, string $status, string $phase, array $metadata = []): Order
    {
        if ($order->status === $status && $order->transaction_phase === $phase) {
            return $order;
        }

        if (! $this->isAllowedTransition($order->status, $status)) {
            throw new InvalidOrderStateException("Invalid order transition from {$order->status} to {$status}.");
        }

        $previousStatus = $order->status;
        $previousPhase = $order->transaction_phase;

        $order->update([
            'status' => $status,
            'transaction_phase' => $phase,
        ]);

        $this->recordEvent($order, 'state_transition', array_merge($metadata, [
            'from_status' => $previousStatus,
            'to_status' => $status,
            'previous_phase' => $previousPhase,
        ]));

        Log::info('Order state transition', [
            'order_id' => $order->id,
            'from_status' => $previousStatus,
            'to_status' => $status,
            'phase' => $phase,
            'trace_id' => $order->trace_id,
        ]);

        return $order;
    }

    public function recordEvent(Order $order, string $eventType, array $metadata = []): OrderEvent
    {
        $event = OrderEvent::create([
            'order_id' => $order->id,
            'event_type' => $eventType,
            'from_status' => $order->status,
            'to_status' => $order->status,
            'source' => 'checkout_state_machine',
            'trace_id' => $order->trace_id,
            'metadata' => $metadata,
            'created_at' => now(),
        ]);

        Log::info('Order event recorded', [
            'order_id' => $order->id,
            'event_type' => $eventType,
            'metadata' => $metadata,
            'trace_id' => $order->trace_id,
        ]);

        return $event;
    }

    private function isAllowedTransition(string $from, string $to): bool
    {
        return isset(self::ALLOWED_TRANSITIONS[$from]) && in_array($to, self::ALLOWED_TRANSITIONS[$from], true);
    }

}
