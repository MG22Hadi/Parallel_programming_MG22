<?php

namespace App\Models;

use App\Enums\PaymentStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentIntent extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_id',
        'provider',
        'provider_reference',
        'amount',
        'currency',
        'status',
        'idempotency_key',
        'attempts',
        'next_retry_at',
        'expires_at',
        'raw_response',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'next_retry_at' => 'datetime',
        'expires_at' => 'datetime',
        'raw_response' => 'array',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function isPending(): bool
    {
        return $this->status === PaymentStatus::PENDING->value;
    }

    public function isFailed(): bool
    {
        return $this->status === PaymentStatus::FAILED->value;
    }

    public function isCaptured(): bool
    {
        return $this->status === PaymentStatus::CAPTURED->value;
    }

    public function scopePending($query)
    {
        return $query->where('status', PaymentStatus::PENDING->value);
    }
}
