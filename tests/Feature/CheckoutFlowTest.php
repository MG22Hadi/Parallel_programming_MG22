<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Jobs\CompensationJob;
use App\Jobs\PaymentExecutionJob;
use App\Jobs\ProcessOrderPostActions;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Order;
use App\Models\PaymentIntent;
use App\Models\Product;
use App\Models\User;
use App\Services\Checkout\OrderStateMachineService;
use App\Services\Payments\FakePaymentGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class CheckoutFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_successful_checkout_creates_order_decrements_stock_creates_payment_intent_clears_cart_and_dispatches_payment_job(): void
    {
        Config::set('payments.fake_mode', 'success');
        Queue::fake();

        $user = User::factory()->create();
        $product = Product::create(['name' => 'Laptop', 'price' => 1000, 'stock' => 5, 'version' => 1]);
        $cart = Cart::create(['user_id' => $user->id]);
        CartItem::create(['cart_id' => $cart->id, 'product_id' => $product->id, 'quantity' => 1]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/checkout', [], ['Idempotency-Key' => 'checkout-test-1']);

        $response->assertStatus(201)
            ->assertJson([
                'status' => OrderStatus::PAYMENT_PENDING->value,
                'message' => 'Checkout initiated',
            ]);

        $orderId = $response->json('order_id');

        $this->assertDatabaseHas('orders', [
            'id' => $orderId,
            'user_id' => $user->id,
            'status' => OrderStatus::PAYMENT_PENDING->value,
        ]);

        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'stock' => 4,
        ]);

        $this->assertDatabaseHas('payment_intents', [
            'order_id' => $orderId,
            'status' => PaymentStatus::PENDING->value,
        ]);

        $this->assertDatabaseMissing('cart_items', ['cart_id' => $cart->id]);

        Queue::assertPushed(PaymentExecutionJob::class);

        $this->assertDatabaseHas('order_events', [
            'order_id' => $orderId,
            'event_type' => 'checkout_started',
        ]);
    }

    public function test_insufficient_stock_returns_422_and_does_not_create_order(): void
    {
        Config::set('payments.fake_mode', 'success');
        Queue::fake();

        $user = User::factory()->create();
        $product = Product::create(['name' => 'Laptop', 'price' => 1000, 'stock' => 1, 'version' => 1]);
        $cart = Cart::create(['user_id' => $user->id]);
        CartItem::create(['cart_id' => $cart->id, 'product_id' => $product->id, 'quantity' => 2]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/checkout', [], ['Idempotency-Key' => 'checkout-test-2']);

        $response->assertStatus(422)
            ->assertJson([
                'error' => 'InsufficientStockException',
            ]);

        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'stock' => 1,
        ]);

        Queue::assertNothingPushed();
    }

    public function test_duplicate_idempotency_request_returns_same_order_and_does_not_double_decrement_stock(): void
    {
        Config::set('payments.fake_mode', 'success');
        Queue::fake();

        $user = User::factory()->create();
        $product = Product::create(['name' => 'Laptop', 'price' => 1000, 'stock' => 5, 'version' => 1]);
        $cart = Cart::create(['user_id' => $user->id]);
        CartItem::create(['cart_id' => $cart->id, 'product_id' => $product->id, 'quantity' => 1]);

        $firstResponse = $this->actingAs($user, 'sanctum')
            ->postJson('/api/checkout', [], ['Idempotency-Key' => 'checkout-test-3']);

        $firstResponse->assertStatus(201);
        $orderId = $firstResponse->json('order_id');

        $secondResponse = $this->actingAs($user, 'sanctum')
            ->postJson('/api/checkout', [], ['Idempotency-Key' => 'checkout-test-3']);

        $secondResponse->assertStatus(200)
            ->assertJson([
                'order_id' => $orderId,
                'message' => 'Duplicate request',
            ]);

        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'stock' => 4,
        ]);
    }

    public function test_failed_payment_compensation_restores_stock_and_marks_order_failed(): void
    {
        Config::set('payments.fake_mode', 'failure');
        Queue::fake();

        $user = User::factory()->create();
        $product = Product::create(['name' => 'Laptop', 'price' => 1000, 'stock' => 1, 'version' => 1]);
        $cart = Cart::create(['user_id' => $user->id]);
        CartItem::create(['cart_id' => $cart->id, 'product_id' => $product->id, 'quantity' => 1]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/checkout', [], ['Idempotency-Key' => 'checkout-test-4']);

        $response->assertStatus(201);

        $paymentIntent = PaymentIntent::first();
        $order = Order::find($paymentIntent->order_id);

        $job = new PaymentExecutionJob($paymentIntent);
        $job->handle(app(FakePaymentGateway::class), app(OrderStateMachineService::class));

        $order->refresh();
        $paymentIntent->refresh();

        $this->assertEquals(OrderStatus::FAILED->value, $order->status);
        $this->assertEquals(PaymentStatus::FAILED->value, $paymentIntent->status);
        Queue::assertPushed(CompensationJob::class);

        $compensationJob = new CompensationJob($order);
        $compensationJob->handle(app(OrderStateMachineService::class));

        $order->refresh();

        $this->assertEquals(OrderStatus::CANCELLED->value, $order->status);
        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'stock' => 1,
        ]);
        $this->assertDatabaseHas('order_events', [
            'order_id' => $order->id,
            'event_type' => 'compensation_completed',
        ]);
        $this->assertDatabaseHas('order_events', [
            'order_id' => $order->id,
            'event_type' => 'cancelled',
        ]);
    }

    public function test_concurrent_checkout_protection_prevents_oversell_for_low_stock_product(): void
    {
        Config::set('payments.fake_mode', 'success');
        Queue::fake();

        $product = Product::create(['name' => 'Laptop', 'price' => 1000, 'stock' => 1, 'version' => 1]);

        $firstUser = User::factory()->create();
        $secondUser = User::factory()->create();

        $firstCart = Cart::create(['user_id' => $firstUser->id]);
        CartItem::create(['cart_id' => $firstCart->id, 'product_id' => $product->id, 'quantity' => 1]);

        $secondCart = Cart::create(['user_id' => $secondUser->id]);
        CartItem::create(['cart_id' => $secondCart->id, 'product_id' => $product->id, 'quantity' => 1]);

        $firstResponse = $this->actingAs($firstUser, 'sanctum')
            ->postJson('/api/checkout', [], ['Idempotency-Key' => 'checkout-test-5']);

        $firstResponse->assertStatus(201);

        $secondResponse = $this->actingAs($secondUser, 'sanctum')
            ->postJson('/api/checkout', [], ['Idempotency-Key' => 'checkout-test-6']);

        $secondResponse->assertStatus(422)
            ->assertJson([ 'error' => 'InsufficientStockException' ]);

        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'stock' => 0,
        ]);
    }
}
