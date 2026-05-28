<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompensationJob extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_id',
        'type',
        'status',
        'attempts',
        'max_attempts',
        'next_attempt_at',
        'payload',
        'result',
        'error_reason',
    ];

    protected $casts = [
        'payload' => 'array',
        'result' => 'array',
        'next_attempt_at' => 'datetime',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function canRetry(): bool
    {
        return $this->status !== CompensationStatus::SUCCEEDED->value
            && $this->attempts < $this->max_attempts;
    }

    public function scopePending($query)
    {
        return $query->where('status', CompensationStatus::PENDING->value);
    }
}
