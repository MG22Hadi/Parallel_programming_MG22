<?php

namespace App\Enums;

enum OrderStatus: string
{
    case CART = 'cart';
    case CHECKOUT_STARTED = 'checkout_started';
    case STOCK_RESERVED = 'stock_reserved';
    case PAYMENT_PENDING = 'payment_pending';
    case PAID = 'paid';
    case COMPLETED = 'completed';
    case FAILED = 'failed';
    case CANCELLED = 'cancelled';
    case EXPIRED = 'expired';
}
