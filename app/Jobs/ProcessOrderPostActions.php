<?php

namespace App\Jobs;

use App\Enums\OrderStatus;
use App\Enums\TransactionPhase;
use App\Models\Order;
use App\Services\Checkout\OrderStateMachineService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessOrderPostActions implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public Order $order;

    public function __construct(Order $order)
    {
        $this->order = $order;
        $this->onQueue(config('queue.queues.orders'));
    }

    public function handle(OrderStateMachineService $stateMachine): void
    {
        Log::info('Order post-actions started', [
            'order_id' => $this->order->id,
        ]);

        $order = Order::find($this->order->id);
        if (!$order || $order->status !== OrderStatus::PAID->value) {
            return;
        }

        // 1. توليد الفاتورة PDF
        // TODO: Generate invoice PDF and store or attach it.

        // 2. إرسال بريد إلكتروني تأكيدي
        // TODO: Mail::to($this->order->user)->send(new OrderConfirmed($this->order));

        // 3. تحديث منظومة الجرد الخارجي أو إشعار النظام الداخلي
        // TODO: Dispatch external inventory sync, analytics, or notifications.

        $stateMachine->transition(
            $order,
            OrderStatus::COMPLETED->value,
            TransactionPhase::COMPLETED->value,
            ['payment_intent_id' => $order->payment_intent_id]
        );

        $stateMachine->recordEvent($order, 'completed', []);

        Log::info('Order post-actions completed', [
            'order_id' => $order->id,
        ]);
    }
}
