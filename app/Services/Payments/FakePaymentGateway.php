<?php

namespace App\Services\Payments;

use Illuminate\Support\Str;

class FakePaymentGateway
{
    public function authorize(array $data): array
    {
        $mode = config('payments.fake_mode', 'random');
        $status = $this->responseStatus($mode);

        return [
            'status' => $status === 'success' ? 'success' : 'failure',
            'reference' => 'fake_' . Str::random(10),
            'message' => $status === 'success' ? null : ($status === 'timeout' ? 'Payment authorization timed out.' : 'Authorization failed.'),
            'provider_reference' => 'fake_auth_' . uniqid(),
            'amount' => $data['amount'] ?? 0,
            'currency' => $data['currency'] ?? 'USD',
            'raw_response' => [
                'mode' => $mode,
                'status' => $status,
                'timestamp' => now()->toDateTimeString(),
            ],
        ];
    }

    public function capture(string $intentReference): array
    {
        $mode = config('payments.fake_mode', 'random');
        $status = $this->responseStatus($mode);

        return [
            'status' => $status === 'success' ? 'success' : 'failure',
            'reference' => $intentReference,
            'message' => $status === 'success' ? null : ($status === 'timeout' ? 'Payment capture timed out.' : 'Capture failed.'),
            'raw_response' => [
                'status' => $status,
                'captured_at' => now()->toDateTimeString(),
            ],
        ];
    }

    private function responseStatus(string $mode): string
    {
        return match ($mode) {
            'success' => 'success',
            'failure' => 'failure',
            'timeout' => 'timeout',
            default => $this->randomStatus(),
        };
    }

    private function randomStatus(): string
    {
        $seed = random_int(1, 100);

        if ($seed <= 70) {
            return 'success';
        }

        if ($seed <= 90) {
            return 'failure';
        }

        return 'timeout';
    }
}
