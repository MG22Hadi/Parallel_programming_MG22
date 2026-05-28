<?php

namespace App\Http\Controllers;

use App\Exceptions\DuplicateIdempotencyKeyException;
use App\Exceptions\InsufficientStockException;
use App\Exceptions\InvalidOrderStateException;
use App\Http\Resources\OrderResource;
use App\Services\Checkout\CheckoutTransactionService;
use App\Services\Idempotency\IdempotencyService;
use Illuminate\Http\Request;

class OrderController extends Controller
{
    public function __construct(
        private CheckoutTransactionService $checkoutService,
        private IdempotencyService $idempotencyService
    ) {
    }

    public function checkout(Request $request)
    {
        $idempotencyKey = $request->header('Idempotency-Key');

        if (!$idempotencyKey) {
            return response()->json([
                'error' => 'MissingHeader',
                'message' => 'Missing Idempotency-Key header.',
            ], 400);
        }

        $fingerprint = [
            'user_id' => $request->user()->id,
            'route' => $request->path(),
            'body' => $request->all(),
        ];

        try {
            $record = $this->idempotencyService->acquire($idempotencyKey, $request->user(), $fingerprint);
        } catch (DuplicateIdempotencyKeyException $exception) {
            return response()->json([
                'error' => 'DuplicateIdempotencyKeyException',
                'message' => $exception->getMessage(),
            ], 409);
        }

        if ($record->isCompleted()) {
            $responseBody = array_merge($record->response_body ?? [], ['message' => 'Duplicate request']);
            return response()->json($responseBody, 200);
        }

        try {
            $order = $this->checkoutService->execute($request->user(), $idempotencyKey, $fingerprint);
        } catch (InsufficientStockException $exception) {
            return response()->json([
                'error' => 'InsufficientStockException',
                'message' => $exception->getMessage(),
            ], 422);
        } catch (InvalidOrderStateException $exception) {
            return response()->json([
                'error' => 'InvalidOrderStateException',
                'message' => $exception->getMessage(),
            ], 422);
        } catch (\Throwable $exception) {
            return response()->json([
                'error' => 'CheckoutException',
                'message' => $exception->getMessage(),
            ], 500);
        }

        $response = array_merge(
            OrderResource::make($order)->resolve(),
            ['message' => 'Checkout initiated']
        );

        $this->idempotencyService->resolve($record, $response);

        return response()->json($response, 201);
    }
}
