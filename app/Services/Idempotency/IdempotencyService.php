<?php

namespace App\Services\Idempotency;

use App\Exceptions\DuplicateIdempotencyKeyException;
use App\Models\IdempotencyKey;
use App\Models\User;

class IdempotencyService
{
    public function acquire(string $key, ?User $user, array $fingerprint): IdempotencyKey
    {
        $record = IdempotencyKey::firstWhere('key', $key);

        if (!$record) {
            return IdempotencyKey::create([
                'user_id' => $user?->id,
                'key' => $key,
                'resource_type' => 'checkout_order',
                'request_fingerprint' => $fingerprint,
                'status' => 'in_progress',
                'attempts' => 1,
                'last_attempt_at' => now(),
                'expires_at' => now()->addDay(),
            ]);
        }

        if ($this->fingerprintMatches($record, $fingerprint)) {
            if ($record->isCompleted()) {
                return $record;
            }

            $record->update([
                'attempts' => $record->attempts + 1,
                'last_attempt_at' => now(),
            ]);

            return $record;
        }

        throw new DuplicateIdempotencyKeyException('Idempotency key already used for a different request fingerprint.');
    }

    public function resolve(IdempotencyKey $record, mixed $response): IdempotencyKey
    {
        $payload = is_array($response) ? $response : ['data' => $response];

        $record->update([
            'status' => 'completed',
            'response_code' => 201,
            'response_body' => $payload,
            'attempts' => $record->attempts + 1,
            'last_attempt_at' => now(),
            'expires_at' => now()->addDay(),
        ]);

        return $record;
    }

    private function fingerprintMatches(IdempotencyKey $record, array $fingerprint): bool
    {
        return $record->request_fingerprint === $fingerprint;
    }
}
