<?php

namespace App\Enums;

enum TransactionPhase: string
{
    case CART = 'cart';
    case CHECKOUT_STARTED = 'checkout_started';
    case STOCK_RESERVED = 'stock_reserved';
    case PAYMENT_PENDING = 'payment_pending';
    case PAYMENT_AUTHORIZED = 'payment_authorized';
    case PAID = 'paid';
    case COMPLETED = 'completed';
    case ROLLBACK_REQUIRED = 'rollback_required';
}
