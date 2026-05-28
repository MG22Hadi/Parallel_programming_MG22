<?php

namespace App\Enums;

enum CompensationType: string
{
    case STOCK_RESTORE = 'stock_restore';
    case PAYMENT_VOID = 'payment_void';
    case ORDER_CANCEL = 'order_cancel';
}
