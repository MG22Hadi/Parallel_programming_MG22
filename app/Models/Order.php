<?php

namespace App\Models;

use App\Enums\OrderStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'payment_intent_id',
        'total_price',
        'currency',
        'status',
        'transaction_phase',
        'idempotency_key',
        'trace_id',
        'correlation_id',
        'failure_code',
        'failure_reason',
        'retry_count',
        'next_retry_at',
        'expires_at',
        'processed_at',
        'completed_at',
        'failed_at',
    ];

    protected $casts = [
        'total_price' => 'decimal:2',
        'next_retry_at' => 'datetime',
        'expires_at' => 'datetime',
        'processed_at' => 'datetime',
        'completed_at' => 'datetime',
        'failed_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function paymentIntent(): BelongsTo
    {
        return $this->belongsTo(PaymentIntent::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(OrderEvent::class);
    }

    public function compensationJobs(): HasMany
    {
        return $this->hasMany(CompensationJob::class);
    }

    public function isPending(): bool
    {
        return $this->status === OrderStatus::PAYMENT_PENDING->value;
    }

    public function isCompleted(): bool
    {
        return $this->status === OrderStatus::COMPLETED->value;
    }

    public function isFailed(): bool
    {
        return $this->status === OrderStatus::FAILED->value;
    }

    public function scopePending($query)
    {
        return $query->where('status', OrderStatus::PAYMENT_PENDING->value);
    }

    public function scopeFailed($query)
    {
        return $query->where('status', OrderStatus::FAILED->value);
    }

    public function scopeStockReserved($query)
    {
        return $query->where('transaction_phase', 'stock_reserved');
    }
}
